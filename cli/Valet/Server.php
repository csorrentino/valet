<?php

namespace Valet;

class Server
{
    public $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Extract $uri from $SERVER['REQUEST_URI'] variable
     *
     * @param   string  $requestUri  $_SERVER['REQUEST_URI']
     * @return  string
     */
    public function uriFromRequestUri(string $requestUri): string
    {
        return rawurldecode(
            explode('?', $requestUri)[0]
        );
    }

    /**
     * Extract site name from HTTP host, stripping www. and supporting wildcard DNS
     *
     * @param   string  $httpHost
     * @return  string
     */
    public function siteNameFromHttpHost(string $httpHost): string
    {
        $siteName = basename(
            // Filter host to support wildcard dns feature
            $this->allowWildcardDnsDomains($httpHost),
            '.'.$this->config['tld']
        );

        if (strpos($siteName, 'www.') === 0) {
            $siteName = substr($siteName, 4);
        }

        return $siteName;
    }

    /**
     * You may use wildcard DNS provider nip.io as a tool for testing your site via an IP address.
     * First, determine the IP address of your local computer (like 192.168.0.10).
     * Then, visit http://project.your-ip.nip.io - e.g.: http://laravel.192.168.0.10.nip.io.
     *
     * @param   string  $domain
     * @return  string
     */
    public function allowWildcardDnsDomains(string $domain): string
    {
        $services = [
            '.*.*.*.*.nip.io',
            '-*-*-*-*.nip.io',
        ];

        if (isset($this->config['tunnel_services'])) {
            $services = array_merge($services, (array) $this->config['tunnel_services']);
        }

        $patterns = [];
        foreach ($services as $service) {
            $pattern = preg_quote($service, '#');
            $pattern = str_replace('\*', '.*', $pattern);
            $patterns[] = '(.*)' . $pattern;
        }

        $pattern = implode('|', $patterns);

        if (preg_match('#(?:' . $pattern . ')\z#u', $domain, $matches)) {
            $domain = array_pop($matches);
        }

        if (strpos($domain, ':') !== false) {
            $domain = explode(':', $domain)[0];
        }

        return $domain;
    }

    /**
     * Extract the domain from the site name.
     *
     * @param  string  $siteName
     * @return string
     */
    public function domainFromSiteName(string $siteName): string
    {
        return array_slice(explode('.', $siteName), -1)[0];
    }

    /**
     * Determine the fully qualified path to the site.
     * Inspects registered path directories, case-sensitive.
     *
     * @param  string  $siteName
     * @return string
     */
    public function sitePath(string $siteName): string
    {
        $valetSitePath = null;
        $domain = $this->domainFromSiteName($siteName);

        foreach ($this->config['paths'] as $path) {
            $handle = opendir($path);

            if ($handle === false) {
                continue;
            }

            $dirs = [];

            while (false !== ($file = readdir($handle))) {
                if (is_dir($path . '/' . $file) && !in_array($file, ['.', '..'])) {
                    $dirs[] = $file;
                }
            }

            closedir($handle);

            // Note: strtolower used below because Nginx only tells us lowercase names
            foreach ($dirs as $dir) {
                if (strtolower($dir) === $siteName) {
                    // early return when exact match for linked subdomain
                    return $path . '/' . $dir;
                }

                if (strtolower($dir) === $domain) {
                    // no early return here because the foreach may still have some subdomains to process with higher priority
                    $valetSitePath = $path . '/' . $dir;
                }
            }

            if ($valetSitePath) {
                return $valetSitePath;
            }
        }
    }

    /**
     * Show the Valet 404 "Not Found" page.
     */
    public function show404()
    {
        http_response_code(404);
        require __DIR__ . '/cli/templates/404.html';
        exit;
    }

    /**
     * @return string|null If set, default site path for uncaught urls
     **/
    public function defaultSitePath(): ?string
    {
        if (isset($this->config['default']) && is_string($this->config['default']) && is_dir($this->config['default'])) {
            return $this->config['default'];
        }
    }

    /**
     * Show directory listing or 404 if directory doesn't exist.
     *
     * @param  string  $valetSitePath
     * @param  string  $uri
     */
    public function showDirectoryListing(string $valetSitePath, string $uri)
    {
        $is_root = ($uri == '/');
        $directory = ($is_root) ? $valetSitePath : $valetSitePath . $uri;

        if (!file_exists($directory)) {
            show_valet_404();
        }

        // Sort directories at the top
        $paths = glob("$directory/*");
        usort($paths, function ($a, $b) {
            return (is_dir($a) == is_dir($b)) ? strnatcasecmp($a, $b) : (is_dir($a) ? -1 : 1);
        });

        // Output the HTML for the directory listing
        echo "<h1>Index of $uri</h1>";
        echo '<hr>';
        echo implode("<br>\n", array_map(function ($path) use ($uri, $is_root) {
            $file = basename($path);

            return ($is_root) ? "<a href='/$file'>/$file</a>" : "<a href='$uri/$file'>$uri/$file/</a>";
        }, $paths));

        exit;
    }
}
