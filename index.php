<?php
/* Run this file */
$share = new share();

/* Class which eases the rendering of simple html pages */
class htmlPage {
	private $body;
	private $title;
	private $head;

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
					'<!-- body -->',
				),
				array(
					$this->title,
					$this->head,
					$this->body,
				),
				file_get_contents('template.html')
			);
		else
			return "<!DOCTYPE html><html><head><meta charset='utf-8'><title>$this->title</title>$this->head</head><body>$this->body</body></html>";
	}
	
	/* Add content to body */
	public function addBody($content) {
		$this->body .= $content;
	}
	
	/* Add content to head */
	public function addHead($content) {
		$this->head .= $content;
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
		$this->setRequest();
		$this->handleRequest();
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
	
	/* List allowed roots */
	private function cliListRoots() {
		$entries = $this->db->query('SELECT * FROM roots');
		echo "Files and folders in the following roots can be shared:\n\n";
		while($file = $entries->fetchArray())
			echo $file['path'] . "\n";
	}
	
	/* Add a path to allowed roots */
	private function cliAddRoot() {
		$this->cliQueryForArgs(
			$this->db->prepare('INSERT INTO roots (path) VALUES (:path)'),
			"Added :path to allowed roots\n"
		);
	}
	
	/* Remove a path from allowed roots */
	private function cliDelRoot() {
		$this->cliQueryForArgs(
			$this->db->prepare('DELETE FROM roots WHERE path=:path'),
			"Removed :path from allowed roots\n"
		);
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
			/* Resolve path */
			if($path)
				$f = realpath($f);
			
			/* Generate message */
			$m = str_replace(':path', $f, $message);
			
			/* Bind path */
			$query->bindValue(':path', SQLite3::escapeString($f), SQLITE3_TEXT);
			
			/* Calculate and bind/replace hash */
			if($hash) {
				$hash = hash($this->config['algorithm'], $f . microtime());
				$query->bindValue(':hash', $hash, SQLITE3_TEXT);
				$m = str_replace(':hash', $hash, $m);
			}
			
			/* Execute query */
			if($query->execute())
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
			"    del [hash(es)]    Stop sharing file(s) with hash(es)\n".
			"    listroots         List allowed roots\n".
			"    addroot [roots]   Add path(s) to allowed roots\n".
			"    delroot [roots]   Remove path(s) from allowed roots\n"
		);
	}
	
	/* Get allowed roots from database */
	public function setRoots() {
		$roots = $this->db->query('SELECT * from roots');
		while($root = $roots->fetchArray())
			$this->roots[] = $root[0];
	}
	
	/* Get the path for the requested file */
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
		
		/* Check if request still has values, else 404 */
		if(!$request)
			require(__DIR__.'/src/404.php');

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
			$page = new htmlPage(basename($this->request) . ' - Shared');
			
			/* Page header */
			$page->addBody('<h1><i class="directory open"></i>' . basename($this->request) . '</h1>');
			
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
		if(file_exists(__DIR__.'/config.ini'))
			$this->config = parse_ini_file(__DIR__.'/config.ini');
		else
			$this->config = array('algorithm'=>'sha1', 'database' => 'share.sqlite3', 'readfile' => false, 'disposition'=> 'attachment', 'address'=>'http://[your address here]');

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
			switch($message) {
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
		$page->addBody('<h1>Error: ' . $code . '</h1><p>' . $message . '</p>');
		$page->render();
		exit;
	}
	
	/* Populate database */
	private function createDB() {
		$this->db = new SQLite3(__DIR__ . '/' . $this->config['database']);
		$this->db->exec('CREATE TABLE roots(path TEXT)');
		$this->db->exec('CREATE TABLE files(hash TEXT PRIMARY KEY, path TEXT)');
	}
}
