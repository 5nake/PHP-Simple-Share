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
		
		/* Get required information and route request */
		$this->setRoots();
		
		if($this->setRequest())
			$this->admin();
		else
			$this->handleRequest();
	}
	
	private function admin() {
		session_start();
		if(isset($_SESSION['login']) && $_SESSION['login'] === true)
			$this->adminInterface();
		else
			$this->adminLogin();
	}
	
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
	
	private function adminRequest() {
		if(isset($_REQUEST['path']))
			$this->queryForFile(
				$this->db->prepare('INSERT INTO files (hash,path) VALUES (:hash, :path)'),
				$_REQUEST['path'],
				true
			);
		if(isset($_REQUEST['hash']))
			foreach($_REQUEST['hash'] as $hash)
				$this->queryForFile(
				$this->db->prepare('DELETE FROM files WHERE hash=:path'),
				$hash,
				true,
				false
			);
	}
	
	private function adminTable() {
		$entries = $this->db->query('SELECT * FROM files');
		
		/* Initialise fileinfo handle to check mime type */
	    $finfo = finfo_open(FILEINFO_MIME_TYPE);
		
		$html = '<form method="post"><table class="sharelist">';
		while($file = $entries->fetchArray())
			$html .= '<tr><td><a href="' . $this->config['address'] . '/' . $file['hash'] . '"><i class="' . @finfo_file($finfo, $file['path']) . '"></i>' .  htmlspecialchars($file['path']) . '</a></td><td><input type="checkbox" name="hash[]" value="' . $file['hash'] . '" /></td><tr>';
		$html .= '<tr><td></td><td><input type="submit" value="Del"/></td></tr></table></form>';
		return $html;
	}
	
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
	
	private function queryForFile($query, $file, $hash = false, $path = true) {
		/* Resolve path */
		if($path)
			$file = realpath($file);
		
		/* Bind path */
		$query->bindValue(':path', SQLite3::escapeString($file), SQLITE3_TEXT);
		
		/* Calculate and bind hash */
		if($hash) {
			$hash = hash($this->config['algorithm'], $file . microtime());
			$query->bindValue(':hash', $hash, SQLITE3_TEXT);
		}
		
		/* Execute query */
		return $query->execute();
	}
	
	/* Execute a query based on arguments */
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
			
			/* Calculate and replace hash */
			if($hash) {
				$hash = hash($this->config['algorithm'], $f . microtime());
				$m = str_replace(':hash', $hash, $m);
			}
			if($this->queryForFile($query, $f, $hash, $path))
				echo $m;
		}
	}
	
	/* Show help for CLI */
	private function cliHelp() {
		exit(
			"Usage:\n".
			"  index.php [share|list|addroot|delroot] [path]\n\n".
			"    list              List shared files\n".
			"    share [files]     Share file(s)\n".
			"    del [hash(es)]    Stop sharing file(s) with hash(es)\n"
		);
	}
	
	/* Get allowed roots from database */
	public function setRoots() {
		$roots = $this->db->query('SELECT * from roots');
		while($root = $roots->fetchArray())
			$this->roots[] = $root[0];
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

	/* Check the request and serve it */
	public function handleRequest() {
	    /* Initialise fileinfo handle to check mime type */
	    $finfo = finfo_open(FILEINFO_MIME_TYPE);
	    
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
				if(substr($file,0,1) != '.' && !empty($file))
					$page->addBody('<a href="' . $_SERVER['REQUEST_URI'] . '/' . rawurlencode($file) . '"><i class="' . finfo_file($finfo, $this->request . '/' . $file) . '"></i>' . htmlspecialchars($file) . '</a><br/>');
				elseif($file == '..')
					$page->addBody('<a href="/' . $this->hash . '"><i class="up"></i>..</a><br/>');
			}
			/* Close fileinfo handle */
			finfo_close($finfo);
			
			/* Render page and exit */
			$page->render();
			exit;
		} else {
			/* Let the webserver handle the file */
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
	
	/* Sets config from file, or default */
	private function setConfig() {
		if(file_exists(__DIR__.'/config.ini')) {
			$this->config = parse_ini_file(__DIR__.'/config.ini');
			if(substr($this->config['password'],0,1) != '$')
				$this->hashConfigPassword();
		} else {
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
		}
	}
	
	private function hashConfigPassword() {
		/* Read config into an array */
		$config = file(__DIR__.'/config.ini');
		
		/* Change the line containing the password with a hashed version */
		foreach($config as $line=>$setting)
			if(substr($setting,0,8) == 'password')
				$config[$line] = 'password    = ' . password_hash($this->config['password'], PASSWORD_BCRYPT, array('cost' => 12)) . "\r\n";
		
		/* Write file back */
		file_put_contents(__DIR__.'/config.ini', implode($config)) or die('Could not write config');
		
		/* Reload config */
		$this->config = parse_ini_file(__DIR__.'/config.ini');
	}
	
	/* Check if request is in allowed roots */
	private function checkRequestPath() {
		foreach($this->roots as $root)
			if(strpos($this->request, $root) === 0) return true;
		return false;
	}
	
	/* Serve an html error */
	private function error($code, $message=null) {
		/* Set response code */
		http_response_code($code);
		
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
	
	/* Populate database */
	private function createDB() {
		$this->db = new SQLite3(__DIR__ . '/' . $this->config['database']);
		$this->db->exec('CREATE TABLE files(hash TEXT PRIMARY KEY, path TEXT)');
	}
}
