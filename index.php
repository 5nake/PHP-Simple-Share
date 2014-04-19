<?php
/* Run this file */
$share = new share();

/* Class which eases the rendering of simple html pages */
class htmlPage {
	private $body;
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
				),
				array(
					$this->title,
					$this->head,
					$this->header,
					$this->body,
				),
				file_get_contents('template.html')
			);
		else
			return "<!DOCTYPE html><html><head><meta charset='utf-8'><title>$this->title</title>$this->head</head><body><h1>$this->header</h1>$this->body</body></html>";
	}
	
	/* Add content to body */
	public function addBody($content) {
		$this->body .= $content;
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
	private $hash;
	private $hashPath;
	private $request;
	private $db;
	private $roots = array();

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
		$request = explode('/', $_SERVER['REQUEST_URI']);
		$curfile = explode('/', $_SERVER['SCRIPT_FILENAME']);
		
		/* Unset all request items that are in the filename */
		for($i = 0; $i < sizeof($curfile); $i++) {
			if(array_key_exists($i, $request) && $request[$i] == $curfile[$i]) {
				unset($request[$i]);
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
		if(!empty($this->config['name']))
			$page->addHeader(htmlspecialchars($this->config['name']));
		
		/* Allow adding of share */
		$page->addBody('<h1>Share file/folder</h1><form method="post">Path: <input class="path" type="text" name="path"/><input type="submit" value="share"/></form>');
		
		/* Get table of shares */
		$page->addBody('<h1>List of shared files/folders</h1>' . $this->adminTable());
		
		/* Render page and exit */
		$page->render();
		exit;
	}
	
	/* Handle post/get requests to the admin interface */
	private function adminRequest() {
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
		while($file = $entries->fetchArray())
			$html .= '<tr><td>' . $this->linkPath($this->config['address'] . '/' . $file['hash'], $file['path']) .'</td><td><input type="checkbox" name="hash[]" value="' . $file['hash'] . '" /></td><tr>';
			
		/* Conclude form and return it */
		$html .= '<tr><td></td><td><input type="submit" value="Del"/></td></tr></table></form>';
		return $html;
	}
	
	/* Handle logins for the admin interface */
	private function adminLogin() {
		/* Check if user tried to login */
		if(isset($_REQUEST['username']) && isset($_REQUEST['password'])  && $_REQUEST['username'] == $this->config['username'] && password_verify($_REQUEST['password'], $this->config['password'])) {
			$_SESSION['login'] = true;
			header('Location: /');
		}
		/* HTML base page */
		$page = new htmlPage(htmlspecialchars('Login – ' . $this->config['name']));
			
		/* Page header */
		if(!empty($this->config['name']))
			$page->addHeader(htmlspecialchars($this->config['name']));
			
		$page->addBody('<form name="login" method="post">
			<table>
				<tr>
					<td>Username:</td>
					<td><input type="text" name="username"/></td>
				</tr>
				<tr>
					<td>Password:</td>
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
			case 'addroot':
				$this->cliAddRoot();
				exit;
			case 'delroot':
				$this->cliDelRoot();
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
	
	/* Execute a query for a file */
	private function queryForFile($query, $file, $hash = false, $path = true) {
		/* Resolve path */
		if($path)
			$file = realpath($file);
		
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
			"  index.php [share|list|addroot|delroot] [path]\n\n".
			"	list			  List shared files\n".
			"	share [files]	 Share file(s)\n".
			"	del [hash(es)]	Stop sharing file(s) with hash(es)\n"
		);
	}
	
	/* Check the request and serve it */
	public function share() { 
		/* Check request path */
		if(!empty($this->request))
			$this->request = realpath($this->request);
		
		/* Error at empty request or non-existing file */
		if(empty($this->request) || !file_exists($this->request))
			$this->error(404);
		
		/* Check if requested file is inside the shared folder AND if path is allowed */
		if(strpos($this->request, $this->hashPath) !== 0 || !$this->checkRequestPath())
			$this->error(403);
		
		/* List files for directories */
		if(is_dir($this->request)) {
			$files = array();
			
			/* Create page */
			$page = new htmlPage(htmlspecialchars(basename($this->request) . ' – ' . $this->config['name']));
			
			/* Page header */
			if(!empty($this->config['name']))
				$page->addHeader(htmlspecialchars($this->config['name']));
			
			/* Folder name */
			$page->addBody('<h1><i class="directory open"></i>' . htmlspecialchars(basename($this->request)) . '</h1>');
			
			/* Populate filelist */
			$handle = opendir($this->request);
			while ($files[] = readdir($handle));
			closedir($handle);
			
			/* Sort files alphabetically */
			natsort($files);
			
			/* Show files */
			foreach($files as $file) {
				/* Strip trailing slash from request */
				$uri = rtrim($_SERVER['REQUEST_URI'], '/');
				
				/* If it's a regular file, add a link to body */
				if(substr($file,0,1) != '.' && !empty($file)) {
					$page->addBody($this->linkPath($uri . '/' . rawurlencode($file), $this->request . '/' . $file, $file) . '<br/>');
				} elseif($file == '..') {
					if($uri != '/' . $this->hash)
					$page->addBody('<a href="' . $uri . '/.."><i class="up"></i>..</a><br/>');
				}
				
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
		if(!empty($this->config['name']))
			$page->addHeader(htmlspecialchars($this->config['name']));
		
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
			
			/* If the filesize is clearly incorrect, recalculate */
			if(!$filesize) {
				$pos = 0;
				$size = 1073741824;
				fseek($file, 0, SEEK_SET);
				while ($size > 1)
				{
					fseek($file, $size, SEEK_CUR);

					if (fgetc($file) === false)
					{
						fseek($file, -$size, SEEK_CUR);
						$size = (int)($size / 2);
					}
					else
					{
						fseek($file, -1, SEEK_CUR);
						$pos += $size;
					}
				}
				while (fgetc($file) !== false)  $pos++;
				$filesize = $pos;
			}
			
			/* Turn the size into human readable format */
			$factor = floor((strlen($filesize) - 1) / 3);
			return sprintf("%.1f", $filesize / pow(1024, $factor)) . ' ' . @$units[$factor];
		} else {
			return 'does not exist';
		}
	}
	
	/* Hash password currently in database and change it in config */
	private function hashConfigPassword() {
		/* Read config into an array */
		$config = file(__DIR__.'/config.ini');
		
		/* Change the line containing the password with a hashed version */
		foreach($config as $line=>$setting)
			if(substr($setting,0,8) == 'password')
				$config[$line] = 'password	= ' . password_hash($this->config['password'], PASSWORD_BCRYPT, array('cost' => 12)) . "\r\n";
		
		/* Write file back */
		file_put_contents(__DIR__.'/config.ini', implode($config)) or die('Could not write config');
		
		/* Reload config */
		$this->config = parse_ini_file(__DIR__.'/config.ini');
	}
	
	/* Generate a hyperlink for a path */
	private function linkPath($link, $file, $name = false) {
		/* Open a fileinfo handle and get the mime type */
		$finfo = finfo_open(FILEINFO_MIME_TYPE);
		$mime = @finfo_file($finfo, $file);
		
		/* Check if a custom name was set, otherwise use filename */
		if(!$name)
			$name = $file;
		
		/* For files (not directories) calculate the filesize */
		$size = "";
		if($mime != 'directory')
			$size = ' (' . $this->fileSize($file) . ')';
		
		/* Close fileinfo handle */
		finfo_close($finfo);
		
		/* Return assembled link */
		return '<a href="' . $link . '"><i class="' . $mime . '"></i> ' . htmlspecialchars($name) . $size . '</a>';	
	}
}
