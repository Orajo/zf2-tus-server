Laminas Tus Server
======

Library for [tus server](http://www.tus.io/) (tus protocol 1.0)

Installation
------------

use [composer](http://getcomposer.org/)

Server Usage
------------
This libbrary depends on [Flysystem](https://flysystem.thephpleague.com/)

```php
/**
 * Laminas action for uploading files
 */
public function uploadAction() {
     // Create and configure server
    $debug = false;
    // The internal adapter
    $adapter = new League\Flysystem\Local\LocalFilesystemAdapter(
        __DIR__.'/storage/'
    );

    // The FilesystemOperator
    $filesystem = new League\Flysystem\Filesystem($adapter);

    $server = new \ZfTusServer\Server('/path/to/save/file', 
                           $this->getRequest(),
                           $adapter
                           $debug
    );

    // Run server
    $server->process(true);
}
```

If you are with an Apache server, add an .htaccess file to redirect all request in the php page (without that, your PATCH call failed), like :

```bash
RewriteEngine on

RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^(.*)$ index.php [QSA,L]
```


Author
------

Jaroslaw Wasilewski <orajo@windowslive.com>.

This library is based on library (https://github.com/leblanc-simon/php-tus) by Simon Leblanc <contact@leblanc-simon.eu>.

License
-------

[MIT](http://opensource.org/licenses/MIT)
