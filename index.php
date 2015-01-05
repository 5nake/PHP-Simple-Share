<?php
/* Run this file */
$share = new share();

/* Class which eases the rendering of simple html pages */
class htmlPage {
	private $body;
	private $class;
    private $title;
	private $head;
	private $header;

	/* Set title on creation of object */
	public function __construct($title = '') {
		$this->title = $title;
	}
	
	/* Output final html */
	public function render() {
		echo $this->getHTML();
	}
	
	/* Generate html */
	public function getHTML() {
		if(file_exists('template.html'))
			return str_replace(
				array(
					'<!-- title -->',
					'<!-- head -->',
					'<!-- header -->',
					'<!-- body -->',
                    '<!-- class -->',
				),
				array(
					$this->title,
					$this->head,
					$this->header,
					$this->body,
                    $this->class,
				),
				file_get_contents('template.html')
			);
		else
			return "<!DOCTYPE html><html><head><meta charset='utf-8'><title>$this->title</title>$this->head</head><body class='$this->class'><h1>$this->header</h1>$this->body</body></html>";
	}
	
	/* Add content to body */
	public function addBody($content) {
		$this->body .= $content;
	}
	
    /* Set a class for the page */
    public function addClass($class) {
        $this->class .= $class . ' ';
    }
    
	/* Add content to head */
	public function addHead($content) {
		$this->head .= $content;
	}
	
	/* Add content to header */
	public function addHeader($content) {
		$this->header .= $content;
	}
	
	/* Set the title of the page */
	public function setTitle($title) {
		$this->title = $title;
	}
}

/* Class which allows for simple filesharing */
class share {
	private $config;
	private $db;
	private $roots = array();
	private $hash;
	private $hashPath;
	private $request;
	private $requestRoot;
	
	/* On object creation */
	public function __construct($config = null) {
		if($config)
			$this->config = $config;
		else
			$this->setConfig();
		
		/* Check for database & Open DB connection */
		if(file_exists(__DIR__ . '/' . $this->config['database']))
			$this->db = new SQLite3(__DIR__ . '/' . $this->config['database']);
		else
			$this->createDB();
		
		/* Detect CLI and run cli functions */
		if(php_sapi_name() == 'cli')
			$this->cli();
		
		/* Set request and server a share or the admin interface */
		if($this->setRequest())
			$this->admin();
		else
			$this->share();
	}
	
	/* Sets config from file, or default */
	private function setConfig() {
		/* Check if config file exists or use default config */
		if(file_exists(__DIR__.'/config.ini')) {
			/* Load config */
			$this->config = parse_ini_file(__DIR__.'/config.ini');
			
			/* Load roots */
			$this->roots = array_map('trim', explode(',', $this->config['allowroots']));
			
			/* If the password hasn't been hashed, do it */
			if(substr($this->config['password'],0,1) != '$')
				$this->hashConfigPassword();
		} else {
			/* Default config */
			$this->config = array(
				'name'=>'PHP Simple Share (Default Config)',
				'algorithm'=>'sha1',
				'database' => 'share.sqlite3',
				'readfile' => false,
				'disposition'=> 'attachment',
				'address'=>'http://[your address here]',
				'username'=>'phpsimpleshare',
				'password'=>'$2y$11$4b3Rob9XGsabL.462DpOvuVclaLuuZJkJ5GBo3zZgKfPjnVTBLmSO'
			);
			$this->roots = array('/');
		}
	}
	
	/* Get the path for the requested file, returns true for admin */
	public function setRequest() {
		/* Generate requested file from url */
		
		$docroot = explode('/', $_SERVER['DOCUMENT_ROOT']);
		$request = explode('?', $_SERVER['REQUEST_URI'], 2)[0];
        $request = explode('/', $request);
		$curfile = explode('/', $_SERVER['SCRIPT_FILENAME']);
		
		/* Change the filename to a relative path */
		foreach($docroot as $l => $path)
			if(array_key_exists($l, $curfile) && $curfile[$l] && $curfile[$l] == $path)
				unset($curfile[$l]);
				
		/* Re-index array */
		$curfile = @array_values($curfile);
		
		/* Now determine the request to the file */
		foreach($curfile as $l => $path) {
			if(array_key_exists($l, $request) && $request[$l] == $path) {
				/* Save the subfolder for later use */
				$this->requestRoot .= $path . '/';
				
				/* Remove it from the request */
				unset($request[$l]);
			}
		}
		
		/* Re-index array */
		$request = @array_values($request);
		
		/* Check if request still has values, else show login */
		if(!$request[0])
			return true;

		/* Store the hash (escaped) */
		$this->hash = SQLite3::escapeString($request[0]);
		
		/* Remove hash from request */ 
		unset($request[0]);
		
		/* Get path from db */
		$this->hashPath = $this->db->querySingle('SELECT path FROM files WHERE hash="' . $this->hash . '"');
		
		/* Assemble full request */
		$this->request = $this->hashPath;
		if(isset($request[1]))
			foreach($request as $req)
				$this->request .= '/' . rawurldecode($req);
	}
	
	/* Check session for valid login and serve either admin page or login page */
	private function admin() {
		session_start();
		if(isset($_SESSION['login']) && $_SESSION['login'] === true)
			$this->adminInterface();
		else
			$this->adminLogin();
	}
	
	/* Provide the admin interface */
	private function adminInterface() {
		if(isset($_REQUEST))
			$this->adminRequest();
		
		/* HTML base page */
		$page = new htmlPage(htmlspecialchars('Admin – ' . $this->config['name']));
			
		/* Page header */
		$this->setHead($page);
		
		/* Allow adding of share */
		$page->addBody('<form class="logout" method="post"><input type="hidden" name="logout" /><input type="submit" value="Logout" /></form><h1 class="top">Share file/folder</h1><form method="post">Path: <input class="path" type="text" name="path"/><input type="submit" value="share"/></form>');
		
		/* Get table of shares */
		$page->addBody('<h1>List of shared files/folders</h1>' . $this->adminTable());
		
		/* Render page and exit */
		$page->render();
		exit;
	}
	
	/* Handle post/get requests to the admin interface */
	private function adminRequest() {
		/* Logout */
		if(isset($_REQUEST['logout'])) {
			$_SESSION['login'] = false;
			header('Location: ' . $this->requestRoot);
			exit;
		}
		
		/* Share new files */
		if(isset($_REQUEST['path']))
			$this->queryForFile(
				$this->db->prepare('INSERT INTO files (hash,path) VALUES (:hash, :path)'),
				$_REQUEST['path'],
				true
			);
		
		/* Delete files from share */
		if(isset($_REQUEST['hash']))
			foreach($_REQUEST['hash'] as $hash)
				$this->queryForFile(
				$this->db->prepare('DELETE FROM files WHERE hash=:path'),
				$hash,
				true,
				false
			);
	}
	
	/* Generate the table for the admin interface */
	private function adminTable() {
		/* Query to get all shared files */
		$entries = $this->db->query('SELECT * FROM files');
		
		/* Initialise fileinfo handle to check mime type */
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		
		/* Start of form/table */
		$html = '<form method="post"><table class="sharelist">';
		
		/* Loop trough all shared files and create hyperlinks */
		while($file = $entries->fetchArray()) {
			if($file)
				$html .= '<tr><td>' . $this->linkPath($this->config['address'] . '/' . $file['hash'], $file['path']) .'</td><td><input type="checkbox" name="hash[]" value="' . $file['hash'] . '" /></td><tr>';
			else
				return "There are no shared files or folders.";
			
		}
			
		/* Conclude form and return it */
		$html .= '<tr><td></td><td><input type="submit" value="Del"/></td></tr></table></form>';
		return $html;
	}
	
	/* Handle logins for the admin interface */
	private function adminLogin() {
		/* Compatibility for PHP 5.3.7 - 5.5.0 */
		if(file_exists(__DIR__ . '/password_compat/lib/password.php'))
			include_once(__DIR__ . '/password_compat/lib/password.php');
	
		/* Check if user tried to login */
		if(isset($_REQUEST['username'])
			&& isset($_REQUEST['password']) 
			&& $_REQUEST['username'] == $this->config['username']
			&& password_verify($_REQUEST['password'], $this->config['password'])) {
			$_SESSION['login'] = true;
			header('Location: ' . $this->requestRoot);
		}
		
		/* HTML base page */
		$page = new htmlPage(htmlspecialchars('Login – ' . $this->config['name']));
			
		/* Page header */
		$this->setHead($page);
			
		$page->addBody('<form name="login" method="post" id="login">
			<table>
				<tr>
					<td class="icon">&#xf007;</td>
					<td><input type="text" name="username"/></td>
				</tr>
				<tr>
					<td class="icon">&#xf023;</td>
					<td><input type="password" name="password"/></td>
				</tr>
				<tr>
				<td></td><td><input type="submit" value="Login"/></td></tr>
			</table>
		</form>');

		$page->render();
		exit;
	}
	
	/* Command Line Interface */
	private function cli() {
		global $argv;
		/* Unset script name */
		unset($argv[0]);
		
		/* Check for arguments */
		if(!isset($argv[1]))
			$this->cliHelp();
		
		/* Execute subcommands */
		switch($argv[1]) {
			case 'share':
				$this->cliShare();
				exit;
			case 'del':
				$this->cliDel();
				exit;
			case 'list':
				$this->cliList();
				exit;
			case 'listroots':
				$this->cliListRoots();
				exit;
            case 'thumbnails':
				$this->cliThumbnails();
				exit;	
		}
		
		/* Ouput help if all else fails */
		$this->cliHelp();
		exit;
	}
	
	/* Add a new shared file/folder */
	private function cliShare() {
		$this->cliQueryForArgs(
			$this->db->prepare('INSERT INTO files (hash,path) VALUES (:hash, :path)'),
			"Link for :path: " . $this->config['address'] . "/:hash\n",
			true
		);
	}
	
	/* List the roots set in the config */
	private function cliListRoots() {
		echo "The following roots are allowed by your configuration.\n".
			"Roots can be added or removed in `" . __DIR__ . "/config.ini`.\n\n";
		foreach($this->roots as $root) {
			echo "  $root\n";
		}
	
	}
    
	/* Generates thumbnail cache for files */
    private function cliThumbnails() {
        global $argv;
        
        if(!isset($argv[2]))
			$this->cliHelp();
		else
			unset($argv[1]);
            
        foreach($argv as $f) {
        
            /* Resolve path */
            $f = truepath($f);
            
            /* Check if item is image */
            $mime = $this->fileMime($f);
            
            /* Images get thumbnails */
            if(explode('/',$mime)[0] == 'image') {
                /* Create a thumbnail */
                $this->thumbnail($f);
                
                /* Output */
                echo "Thumbnail created for $f \n";
            } else {
                /* Output */
                echo "Error: $f is not an image!\n";
            }
        }
    }
	
	/* List shared files/folders */
	private function cliList() {
		$entries = $this->db->query('SELECT * FROM files');
		echo "The following files have been shared:\n\n";
		while($file = $entries->fetchArray())
			echo $file['hash'] . ' ' . $file['path'] . "\n";
	}
	
	/* Add a new shared file/folder */
	private function cliDel() {
		$this->cliQueryForArgs(
			$this->db->prepare('DELETE FROM files WHERE hash=:path'),
			"Stopped sharing file with hash :path\n",
			true,
			false
		);
	}
	
	/* Execute a query based on command line arguments */
	private function cliQueryForArgs($query, $message, $hash = false, $path = true) {
		global $argv;
		
		/* Check for more arguments */
		if(!isset($argv[2]))
			$this->cliHelp();
		else
			unset($argv[1]);
		
		/* Loop trough arguments and execute query for each */
		foreach($argv as $f) {
			/* Generate message */
			$m = str_replace(':path', $f, $message);
			
			/* Execute the query and output result */
			if($this->queryForFile($query, $f, $hash, $path))
				if($hash)
					echo str_replace(':hash', $this->hash, $m);
				else
					echo $m;
		}
	}
	
	/* Show help for CLI */
	private function cliHelp() {
		exit(
			"Usage:\n".
			"  index.php [share|list|del|listroots|thumbnails] [path]\n\n".
			"    list                    List shared files\n".
			"    share [files]           Share file(s)\n".
			"    del [hash(es)]          Stop sharing file(s) with hash(es)\n".
			"    listroots               Show the folders from which files can be shared\n".
			"    thumbnails [files]      Pre-generate thumbnails for file(s)\n"
		);
	}
	
	/* Check the request and serve it */
	public function share() { 
		/* Check request path */
		if(!empty($this->request))
			$this->request = truepath($this->request);
		
		/* Error at empty request or non-existing file */
		if(empty($this->request) || !file_exists($this->request))
			$this->error(404);
		
		/* Check if requested file is inside the shared folder AND if path is allowed */
		if(strpos($this->request, $this->hashPath) !== 0 || !$this->checkRequestPath())
			$this->error(403);
		
		/* List files for directories */
		if(is_dir($this->request)) {
			$files = array();
            
            /* Strip trailing slash and get from request */
            $this->uri = rtrim(explode('?', $_SERVER['REQUEST_URI'], 2)[0], '/');
			
			/* Create page */
			$page = new htmlPage(htmlspecialchars(basename($this->request) . ' – ' . $this->config['name']));
			
			/* Page header */
			$this->setHead($page);
			
			/* Folder name */
			$page->addBody('<h1><i class="directory open"></i>' . htmlspecialchars(basename($this->request)) . '</h1>');
			
			/* Populate filelist */
			$handle = opendir($this->request);
			while ($files[] = readdir($handle));
			closedir($handle);
			
			/* Sort files alphabetically */
            natsort($files);
            
            /* Detect if files should be shown in another way */
            if(isset($_GET['view'])) {
                $view = $_GET['view'];
            } else {
                $view = 'list';
            }
            
            /* Give the page the class of the view so CSS can change */
            $page->addClass('view-' . $view);
            
            /* Functions for views */
            switch($view) {
                case 'gallery':
                    $this->shareViewGallery($files, $page);
                    break;
                case 'list':
                default:
                    $this->shareViewList($files, $page);
                    break;
            }
 			
			/* Render page and exit */
			$page->render();
			exit;
		} else {
			/* Open a fileinfo handle and get the mime type */
			$finfo = finfo_open(FILEINFO_MIME_TYPE);
		
			/* Let the webserver handle the file if possible */
			if($this->config['readfile'])
				readfile($this->request);
			elseif(strstr($_SERVER['SERVER_SOFTWARE'], 'nginx'))
				header('X-Accel-Redirect: ' . $this->request);
			else
				header('X-Sendfile: ' . $this->request);
			
			/* Server other headers */
			header('Content-Type: ' . finfo_file($finfo, $this->request));
			header('Content-Disposition: ' . $this->config['disposition'] . '; filename="' . basename($this->request) . '"');
			
			/* Close fileinfo handle and exit*/
			finfo_close($finfo);
			exit;
		}
	}
    
    /* Will try to generate a thumbnail for a file */
    private function thumbnail($file, $thumbnail = false, $size = 192) {
        /* Generate the thumbnail location */
        if(!$thumbnail)
            $thumbnail = '/cache/' . md5($file) . '.jpg';
    
        /* If no thumbnail exists, generate one */
        if(!file_exists(__DIR__ . $thumbnail)) {
            
            /* Check if the file is jpg to improve performance */
            if(substr($file,-3,3) == 'jpg') {
                $image = new Imagick();
                $image->setOption('jpeg:size', '192x192');
                $image->readImage($file);
            } else {
                $image = new Imagick($file);
            }
            
            /* Get date of the taken photo */
            $time = strtotime($image->getImageProperties('exif:DateTimeOriginal')['exif:DateTimeOriginal']);
            
            /* calculate best scaling */
            $min = min($image->getImageWidth(), $image->getImageHeight());
            $max = max($image->getImageWidth(), $image->getImageHeight());
            $fit = ceil($max / $min * 192);
            //echo $min . '/' . $max . '/' . $fit; die;
            
            /* Create thumbnail */
            $image->thumbnailImage($fit, $fit, true);
            $image->writeImage(__DIR__ . $thumbnail);
            
            /* Set acces/mod time */
            touch(__DIR__ . $thumbnail, $time);
        }
    }
    
    private function shareViewGallery($files, $page) {
        
        /* Add mode button */
        $page->addBody('<a href="' . $this->uri . '" class="viewbutton"><i class="icon list"></i></a>');
        
        /* Load Script */
        $page->addBody('<script src="//ajax.googleapis.com/ajax/libs/jquery/2.1.1/jquery.min.js"></script>
            <script src="/gallery.js"></script>');
            
        /* Load fotoviewer html */
        $page->addBody('
            <div id="photoviewer">
                <div class="photoviewer-close icon"></div>
                <div class="photoviewer-left icon"></div>
                <img id="photoviewer-photo" src="" alt="image not found"/>
                <div class="photoviewer-right icon"></div>
                <div class="photoviewer-name"></div>
                <img id="photoviewer-load" src="/loader.png" alt="Loading image"/>
            </div>');
        
        /* Loop trough all files to be displayed */
        foreach($files as $file) {
            
            /* If it's a regular file, add a link to body */
            if(substr($file,0,1) != '.' && !empty($file)) {
            
                /* Check if item is image */
                $mime = $this->fileMime($this->request . '/' . $file);
                
                /* Images get thumbnails */
                if(explode('/',$mime)[0] == 'image') {
                
                    /* Generate the thumbnail location */
                    $thumbnail = '/cache/' . md5($this->request . '/' . $file) . '.jpg';
                
                    /* Create a thumbnail */
                    $this->thumbnail($this->request . '/' . $file, $thumbnail);
                    
                    /* Get date of the photo from the thumbnail */
                    $name = date('Y-m-d H:i:s', filemtime(__DIR__ . $thumbnail));
                    if(!$name)
                        $name = $file;
                
                    /* Create the html for the image */
                    $content = '<img src="' . $thumbnail . '" alt="' . $name . '" />';
                
                /* Other content gets a big icon */
                } else {
                    $content = '<span class="thumb ' . $mime . '"></span>';
                }
                
                /* Add a link of the image to the body */
                $page->addBody(
                    '<span class="galleryitem">' . 
                    $this->linkPath(
                        $this->uri . '/' . rawurlencode($file) . '?gallery',
                        $this->request . '/' . $file,
                        null,
                        $content 
                    ) . '</span>'
                );
                
            } elseif($file == '..') {
                if($this->uri != '/' . $this->hash)
                $page->addBody('<span class="galleryitem"><a href="' . $this->uri . '/..?gallery"><span class="thumb up"></span></a></span>');
                
            }
        }
    }
    
    /* Creates a nice list of files */
    private function shareViewList($files, $page) {
        
        /* Add mode button */
        $page->addBody('<a href="' . $this->uri . '?view=gallery" class="viewbutton"><i class="icon gallery"></i></a>');
    
        /* Show files */
        foreach($files as $file) {
            /* If it's a regular file, add a link to body */
            if(substr($file,0,1) != '.' && !empty($file)) {
                $page->addBody($this->linkPath($this->uri . '/' . rawurlencode($file), $this->request . '/' . $file, $file) . '<br/>');
            
            /* Only show the 'up' of the rest of the files */
            } elseif($file == '..') {
                if($this->uri != '/' . $this->hash)
                $page->addBody('<a href="' . $this->uri . '/.."><i class="up"></i>..</a><br/>');
            }
        }
    }
	
	/* Check if request is in allowed roots */
	private function checkRequestPath() {
		foreach($this->roots as $root)
			if(strpos($this->request, $root) === 0) return true;
		return false;
	}
	
	/* Populate database */
	private function createDB() {
		$this->db = new SQLite3(__DIR__ . '/' . $this->config['database']);
		$this->db->exec('CREATE TABLE files(hash TEXT PRIMARY KEY, path TEXT)');
	}
	
	/* Serve an html error */
	private function error($code, $message=null) {
		/* Set response code */
		http_response_code($code);
		
		/* Table of fallback messages */
		if(!$message)
			switch($code) {
				case 404:
					$message = 'Not found';
					break;
				case 403:
					$message = 'Forbidden';
					break;
				default:
					break;
			}
		
		/* Generate html error page */
		$page = new htmlPage('Error: ' . $code);
		
		/* Page header */
		$this->setHead($page);
		
		/* Error */
		$page->addBody('<h1>Error: ' . $code . '</h1><p>' . $message . '</p>');
		
		/* Render and exit */
		$page->render();
		exit;
	}
	
	/* Return filesize for any file */
	private function fileSize($file) {
		/* Load units */
		$units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
		
		/* Check if file exists */
		if(file_exists($file)) {
			$filesize = filesize($file);
			
			/* If the filesize is clearly incorrect, don't show it */
			if($filesize) {
				/* Turn the size into human readable format */
				$factor = floor((strlen($filesize) - 1) / 3);
				return sprintf("%.1f", $filesize / pow(1024, $factor)) . ' ' . @$units[$factor];
			}
		} else {
			return 'does not exist';
		}
	}
	
	/* Hash password currently in database and change it in config */
	private function hashConfigPassword() {
		/* Compatibility for PHP 5.3.7 - 5.5.0 */
		if(file_exists(__DIR__ . '/password_compat/lib/password.php'))
			include_once(__DIR__ . '/password_compat/lib/password.php');
	
		/* Read config into an array */
		$config = file(__DIR__.'/config.ini');
		
		/* Change the line containing the password with a hashed version */
		foreach($config as $line=>$setting)
			if(substr($setting,0,8) == 'password')
				$config[$line] = 'password	= ' . password_hash($this->config['password'], PASSWORD_BCRYPT, array('cost' => 12)) . "\r\n";
		
		/* Write file back */
		file_put_contents(__DIR__.'/config.ini', implode($config)) or die('Could not write to `config.ini`');
		
		/* Reload config */
		$this->config = parse_ini_file(__DIR__.'/config.ini');
	}
	
    /* Get mime type for a file */
    private function fileMime($file) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime = @finfo_file($finfo, $file);
        
        /* Close fileinfo handle */
		finfo_close($finfo);
        
        return $mime;
    }
    
	/* Generate a hyperlink for a path */
	private function linkPath($link, $file, $name = false, $content = null) {
		/* Open a fileinfo handle and get the mime type */
		$mime = $this->fileMime($file);
		
        /* Check if html content for the link is given, otherwise use filename and size */
        if(!$content) {
            /* Check if a custom name was set, otherwise use filename */
            if(!$name)
                $name = $file;
		
            /* For files (not directories) calculate the filesize */
            $size = "";
            if($mime != 'directory') {
                if($filesize = $this->fileSize($file))
                    $size = ' (' . $filesize . ')';
            }
            
            /* Use filename and size safely */
            $content = '<i class="' . $mime . '"></i> ' . htmlspecialchars($name) . $size;
        }
		
		/* Return assembled link */
		return '<a href="' . $link . '">' . $content . '</a>';	
	}
	
	/* Execute a query for a file */
	private function queryForFile($query, $file, $hash = false, $path = true) {
		/* Resolve path */
		if($path)
			$file = truepath($file);
		
		/* Bind path */
		$query->bindValue(':path', SQLite3::escapeString($file), SQLITE3_TEXT);
		
		/* Calculate and bind/save hash */
		if($hash) {
			$hash = hash($this->config['algorithm'], $file . microtime());
			$query->bindValue(':hash', $hash, SQLITE3_TEXT);
			$this->hash = $hash;
		}
		
		/* Execute query */
		return $query->execute();
	}
	
	/* Set the header for the page */
	private function setHead($page) {
		/* Page style */
		$page->addHead('<link rel="stylesheet" type="text/css" href="' . $this->requestRoot . 'style.css" />');
	
		/* Page header */
		$page->addHeader('<a href="' . $this->requestRoot . '">' . htmlspecialchars($this->config['name']) . '</a>');
	}
}

/**
 * This function is to replace PHP's extremely buggy realpath().
 * @param string The original path, can be relative etc.
 * @return string The resolved path, it might not exist.
 * @license http://creativecommons.org/licenses/by-sa/3.0/
 * @author Christian (http://stackoverflow.com/a/4050444/1882566), patch by andig
 */
function truepath($path){
	// whether $path is unix or not
	$unipath=strlen($path)==0 || $path{0}!='/';
	// attempts to detect if path is relative in which case, add cwd 
	if(strpos($path,':')===false && $unipath) { 
		$path=getcwd().DIRECTORY_SEPARATOR.$path;
		$unipath=false;
	}
	// resolve path parts (single dot, double dot and double delimiters)
	$path = str_replace(array('/', '\\'), DIRECTORY_SEPARATOR, $path);
	$parts = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'strlen');
	$absolutes = array();
	foreach ($parts as $part) {
		if ('.'  == $part) continue;
		if ('..' == $part) {
			array_pop($absolutes);
		} else {
			$absolutes[] = $part;
		}
	}
	$path=implode(DIRECTORY_SEPARATOR, $absolutes);
	// resolve any symlinks
	if(file_exists($path) && linkinfo($path)>0)$path=readlink($path);
	// put initial separator that could have been lost
	$path=!$unipath ? '/'.$path : $path;
	return $path;
}
