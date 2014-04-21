PHP-Simple-Share
================
This script allows you to share files on your webserver via unique urls. Files can be shared trough a command-line interface or a web-interface.

![Shared Folder][4]

Usage
-----
Let's say I want to share a file named `/share/file.zip`. There are two ways to share this file:

### Web Interface

Login to the web interface, you'll see something like the following

![Admin Interface][5]

To add a file, simply enter the path to the file and click ‘share’.
Paths can be relative and symbolic links will be resolved.

If the file or appears in the list it has been added succesfully. The name also links to your shared file. To share, right-click and select ‘copy link’ or something to that effect.

### Command Line Interface
Run the following to share the file (where `index.php` is the path to your `index.php` file):

	php5 index.php share /share/file.zip
    
This will output something like the following:

	Link for : http://[your address here]/41e8148bd81618f6b63aa43bd6275e5dbab93825
	
This link can be used to share your file, assuming the configured address is correct.

To get help with the command-line-interface, enter:

	php5 index.php
	
This will output the following help:

	Usage:
	  index.php [share|list|addroot|delroot] [path]

	    list              List shared files
	    share [files]     Share file(s)
	    del [hash(es)]    Stop sharing file(s) with hash(es)

Installation
------------
All that is really needed is the `index.php` file, though for stylistic purposes `style.css` and `template.html` are also recommended.
All requests should be routed to `index.php`, more about that in *Server Settings* below.

It also requires the following server settings:

 * PHP >= 5.3.7
 * The PHP SQLite3 extension (enabled by default for PHP >= 5.3.0)

Configuration
-------------
An example config file (`config.dist.ini`) has been provided. It has the following options:

 * `name`:          the name you want for your share.
 * `algorithm`:     the [hash algorithm][3] used.
 * `database`:      the relative path to the sqlite database file.
 * `readfile`:      use php instead of letting the webserver handle the file.
 * `disposition`:   how files should shown. `inline` for display in the browser (if possible), `attachment` if the file
 * `address`:       the address to your share, eg: `http://share.example.com/share/`.
 * `username`:      the username you want to use for the admin interface.
 * `password`:      the password you want to use. The password can be entered in plain-text and will be hashed for you.
 * `allowroots`:    the folders from which files can be shared, separated by a comma.
 
Because the password will be hashed, PHP needs write permissions as well.

Server Settings
---------------
Here are some tips for the configuration of your webserver. For this script to work all requests should be routed to `index.php`. The documentation for the Silex framework has [some information for other servers][2] which also works for this script.

When serving files the script tries to let the webserver handle the actual file handling via either the `X-Sendfile` header or the `X-Accel-Redirect` header. Some more information can be found in [this StackExchange question][1].
If you don't want use this feature, set `readfile` to `true` in the configuration.

### Apache
For Apache (2.2.16 or higher) you can create a `.htaccess` file with the following content:

	FallbackResource /index.php
	<Files ~ \.(php|ini|sqlite3)>
		Order allow,deny
		Deny from all
	</Files>
	
If your site is located in a subfolder (eg: `/share`) replace `/` with your entire path (eg: `/share/index.php`).
	
To let Apache handle the downloading of files, add the following (`mod_xsendfile` needs to be enabled):

	XSendFile on
	XSendFilePath /share

This allows you to share files  in `/share/` either via the absolute path (`/share/file`) or the relative path (`file`).
If you don't use this feature, set `readfile` to `true` in the configuration.
	
### Nginx
Nginx (with php5-fpm) is a little less simple. You should have the following in your site:

	location = / {
		try_files @site @site;
	}

	location / {
		try_files $uri $uri/ @site;
	}
	
	# Block direct access to .php, .ini and .sqlite3 files
	location ~ \.(php|ini|sqlite3)$ {
		return 404;
	}

	location @site {
		fastcgi_pass unix:/var/run/php5-fpm.sock;
		include fastcgi_params;
		fastcgi_param SCRIPT_FILENAME $document_root/index.php;
		#fastcgi_param HTTPS on; # When using HTTPS
	}

If your site is located in a subfolder (eg: `/share`) replace `/` with your entire path (eg: `/share/index.php`).

To let Nginx handle the downloading of files, add the following:

	location /share {
		internal;
		alias /share;
    }

This allows you to share files in `/share/` either via the absolute path (`/share/file`).
If you don't use this feature, set `readfile` to `true` in the configuration.

[1]: http://stackoverflow.com/questions/3697748/fastest-way-to-serve-a-file-using-php
[2]: http://silex.sensiolabs.org/doc/web_servers.html
[3]: http://php.net/manual/en/function.hash-algos.php
[4]: https://dl.dropboxusercontent.com/u/6849076/Github/php-simple-share-1.png
[5]: https://dl.dropboxusercontent.com/u/6849076/Github/php-simple-share-2.png