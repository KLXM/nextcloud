<?php
namespace Klxm\Nextcloud;

class NextCloud {
    private $baseUrl;
    private $username;
    private $password;
    private $rootFolder;

    public function __construct() {
        $this->baseUrl = \rex_config::get('nextcloud', 'baseurl');
        $this->username = \rex_config::get('nextcloud', 'username');
        $this->password = \rex_config::get('nextcloud', 'password');
        $this->rootFolder = \rex_config::get('nextcloud', 'rootfolder', '/');

        if (!$this->baseUrl || !$this->username || !$this->password) {
            throw new \rex_exception('NextCloud configuration missing');
        }

        $this->baseUrl = rtrim($this->baseUrl, '/');
        
        // Normalize root folder
        if ($this->rootFolder && $this->rootFolder !== '/') {
            $this->rootFolder = '/' . trim($this->rootFolder, '/');
        } else {
            $this->rootFolder = '/';
        }
    }

    private function encodeUrl($path) {
        // Entferne alle doppelten Slashes
        $path = preg_replace('#/+#', '/', $path);
        
        // Splitte den Pfad in Segmente
        $segments = explode('/', $path);
        
        // Kodiere jedes Segment einzeln
        $encodedSegments = array_map(function($segment) {
            // Behandle leere Segmente
            if ($segment === '') {
                return '';
            }
            
            // Wandle Umlaute in UTF-8 um
            $segment = mb_convert_encoding($segment, 'UTF-8', 'auto');
            
            // Kodiere alle Sonderzeichen außer -_.
            return rawurlencode($segment);
        }, $segments);
        
        // Verbinde die Segmente wieder und stelle sicher, dass führende/nachfolgende Slashes erhalten bleiben
        $encodedPath = implode('/', array_filter($encodedSegments, function($segment) {
            return $segment !== '';
        }));
        
        // Stelle sicher, dass der Pfad mit einem Slash beginnt
        $encodedPath = '/' . ltrim($encodedPath, '/');
        
        return $encodedPath;
    }

    private function decodeUrl($path) {
        // Dekodiere jeden Teil des Pfads einzeln
        $segments = explode('/', $path);
        
        $decodedSegments = array_map(function($segment) {
            // Dekodiere URL-kodierte Zeichen
            $segment = rawurldecode($segment);
            
            // Stelle sicher, dass die UTF-8 Kodierung korrekt ist
            if (mb_check_encoding($segment, 'UTF-8')) {
                return $segment;
            }
            
            // Versuche die Kodierung zu reparieren
            return mb_convert_encoding($segment, 'UTF-8', 'auto');
        }, $segments);
        
        return implode('/', $decodedSegments);
    }

    private function normalizePath($path) {
        // Dekodiere zuerst den Pfad
        $path = $this->decodeUrl($path);
        
        // Entferne mehrfache Slashes
        $path = preg_replace('#/+#', '/', $path);
        
        // Stelle sicher, dass der Pfad mit einem Slash beginnt
        $path = '/' . trim($path, '/');
        
        // Spezialfall: Wenn der Pfad nur aus Slashes besteht
        if ($path === '//') {
            return '/';
        }
        
        // Kodiere den normalisierten Pfad
        return $this->encodeUrl($path);
    }

    private function buildWebDavUrl($path) {
        // Apply root folder prefix
        $fullPath = $path;
        if ($this->rootFolder !== '/') {
            if ($path === '/') {
                $fullPath = $this->rootFolder;
            } else {
                $fullPath = $this->rootFolder . $path;
            }
        }
        
        // Normalisiere und kodiere den Pfad
        $normalizedPath = $this->normalizePath($fullPath);
        
        // Baue die WebDAV-URL
        $webdavPath = '/remote.php/dav/files/' . rawurlencode($this->username) . $normalizedPath;
        
        \rex_logger::factory()->log('debug', 'NextCloud WebDAV URL', [
            'original_path' => $path,
            'root_folder' => $this->rootFolder,
            'full_path' => $fullPath,
            'normalized_path' => $normalizedPath,
            'webdav_path' => $webdavPath
        ]);
        
        return $webdavPath;
    }

    private function request($path, $method = 'GET', $data = null) {
        $url = $this->baseUrl . $path;
        
        \rex_logger::factory()->log('debug', 'NextCloud Request', [
            'url' => $url,
            'method' => $method
        ]);
        
        $ch = curl_init();
        
        $headers = [];

        if ($method === 'PROPFIND') {
            $headers[] = 'Content-Type: application/xml';
            $headers[] = 'Depth: 1';
            $data = '<?xml version="1.0" encoding="utf-8" ?>
                     <d:propfind xmlns:d="DAV:">
                         <d:prop>
                             <d:getlastmodified />
                             <d:getcontentlength />
                             <d:resourcetype />
                             <d:getetag />
                         </d:prop>
                     </d:propfind>';
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $this->username . ":" . $this->password,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => true,
            // Timeouts
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            // Retry settings
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_FORBID_REUSE => true,
            // Keep-Alive
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_TCP_KEEPIDLE => 120,
            CURLOPT_TCP_KEEPINTVL => 60,
        ];

        if ($data) {
            $options[CURLOPT_POSTFIELDS] = $data;
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            \rex_logger::factory()->log('error', 'NextCloud cURL Error', [
                'error' => $error,
                'code' => curl_errno($ch),
                'url' => $url,
                'info' => curl_getinfo($ch)
            ]);
            curl_close($ch);
            throw new \rex_exception("cURL Error: " . $error);
        }
        
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 400) {
            return $response;
        }
        
        throw new \rex_exception("API request failed with status code: " . $httpCode);
    }
	public function listFiles($path = '/') {
        try {
            \rex_logger::factory()->log('debug', 'NextCloud ListFiles Start', [
                'original_path' => $path
            ]);
            
            // URL für die PROPFIND-Anfrage erstellen
            $url = $this->buildWebDavUrl($path);
            
            \rex_logger::factory()->log('debug', 'NextCloud ListFiles URL', [
                'webdav_url' => $url
            ]);
            
            $response = $this->request($url, 'PROPFIND');
            
            // Entferne ungültige XML-Zeichen
            $response = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $response);
            
            // Debug-Log für XML-Antwort
            \rex_logger::factory()->log('debug', 'NextCloud ListFiles Response', [
                'response_length' => strlen($response),
                'response_preview' => substr($response, 0, 500)
            ]);
            
            // XML parsen
            $previousLibXmlUseErrors = libxml_use_internal_errors(true);
            
            try {
                $xml = new \SimpleXMLElement($response);
            } catch (\Exception $e) {
                \rex_logger::factory()->log('error', 'XML Parse Error', [
                    'error' => $e->getMessage(),
                    'response' => substr($response, 0, 1000)
                ]);
                throw new \rex_exception('Failed to parse server response');
            } finally {
                libxml_use_internal_errors($previousLibXmlUseErrors);
            }
            
            $xml->registerXPathNamespace('d', 'DAV:');
            
            // Sammle alle Dateien und Ordner
            $files = [];
            foreach ($xml->xpath('//d:response') as $response) {
                // Hole den href (Pfad)
                $href = (string)$response->xpath('d:href')[0];
                
                \rex_logger::factory()->log('debug', 'NextCloud ListFiles Entry', [
                    'href' => $href
                ]);

                // Extraktion des Pfads
                $pattern = '#^/remote\.php/dav/files/' . preg_quote($this->username, '#') . '#';
                $relativePath = preg_replace($pattern, '', rawurldecode($href));
                
                // Remove root folder prefix to get display path
                $displayPath = $relativePath;
                if ($this->rootFolder !== '/') {
                    $rootFolderPattern = '#^' . preg_quote($this->rootFolder, '#') . '#';
                    $displayPath = preg_replace($rootFolderPattern, '', $relativePath);
                    if ($displayPath === '') {
                        $displayPath = '/';
                    }
                }
                
                $displayPath = $this->normalizePath($displayPath);
                
                // Name aus dem Pfad extrahieren
                $displayname = basename($displayPath);
                
                // Überspringe den aktuellen Ordner
                if ($displayname === '' || $this->normalizePath($displayPath) === $this->normalizePath($path)) {
                    continue;
                }
                
                // Eigenschaften auslesen
                $props = $response->xpath('d:propstat/d:prop')[0];
                $isDirectory = !empty($props->xpath('d:resourcetype/d:collection'));
                
                $size = '';
                if (!$isDirectory && !empty($props->xpath('d:getcontentlength'))) {
                    $size = $this->formatSize((int)$props->xpath('d:getcontentlength')[0]);
                }
                
                $lastMod = '';
                if (!empty($props->xpath('d:getlastmodified'))) {
                    $lastMod = date('Y-m-d H:i', strtotime((string)$props->xpath('d:getlastmodified')[0]));
                }

                \rex_logger::factory()->log('debug', 'NextCloud ListFiles Processed Entry', [
                    'name' => $displayname,
                    'path' => $relativePath,
                    'is_directory' => $isDirectory,
                    'size' => $size,
                    'modified' => $lastMod
                ]);
                
                $files[] = [
                    'name' => $displayname,
                    'path' => $displayPath,
                    'type' => $isDirectory ? 'folder' : $this->getFileType($displayname),
                    'size' => $size,
                    'modified' => $lastMod
                ];
            }
            
            \rex_logger::factory()->log('debug', 'NextCloud ListFiles Complete', [
                'total_files' => count($files)
            ]);
            
            return $files;
            
        } catch (\Exception $e) {
            \rex_logger::factory()->log('error', 'NextCloud ListFiles Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'path' => $path
            ]);
            throw $e;
        }
    }

    public function getImageContent($path) {
        try {
            $webdavPath = $this->buildWebDavUrl($path);
            return $this->request($webdavPath, 'GET');
        } catch (\Exception $e) {
            throw new \rex_exception("Failed to get image: " . $e->getMessage());
        }
    }

    public function importToMediapool($path, $categoryId = 0) {
        try {
            \rex_logger::factory()->log('debug', 'NextCloud Import', [
                'original_path' => $path
            ]);
            
            // Normalisiere den Pfad
            $url = $this->buildWebDavUrl($path);
            
            \rex_logger::factory()->log('debug', 'NextCloud Import URL', [
                'webdav_url' => $url
            ]);
            
            // Hole den Dateiinhalt
            $content = $this->request($url, 'GET');
            
            // Dekodiere den Dateinamen für die temporäre Datei
            $filename = $this->decodeUrl(basename($path));
            
            // Erstelle einen sicheren Dateinamen für die temporäre Datei
            $tmpName = \rex_string::normalize($filename);
            $tmpfile = \rex_path::cache('nextcloud_' . $tmpName);
            
            \rex_logger::factory()->log('debug', 'NextCloud Import File', [
                'original_filename' => $filename,
                'temp_filename' => $tmpName,
                'temp_path' => $tmpfile
            ]);
            
            if (file_put_contents($tmpfile, $content) === false) {
                throw new \rex_exception('Could not save temporary file');
            }

            // Bereite die Daten für den Medienpool vor
            $data = [];
            $data['file'] = [
                'name' => $filename, // Original-Dateiname
                'path' => $tmpfile,
                'tmp_name' => $tmpfile
            ];
            $data['category_id'] = $categoryId;
            $data['title'] = pathinfo($filename, PATHINFO_FILENAME);

            $result = \rex_media_service::addMedia($data, true);
            
            @unlink($tmpfile);
            
            return $result;
            
        } catch (\Exception $e) {
            \rex_logger::factory()->log('error', 'NextCloud Import Error', [
                'error' => $e->getMessage(),
                'path' => $path,
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    private function getFileType($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
        $documentTypes = ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'md', 'rtf'];
        $pdfTypes = ['pdf'];
        $archiveTypes = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
        $audioTypes = ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'aac'];
        $videoTypes = ['mp4', 'avi', 'mkv', 'mov', 'webm', 'flv', 'wmv'];
        
        if (in_array($ext, $imageTypes)) return 'image';
        if (in_array($ext, $pdfTypes)) return 'pdf';
        if (in_array($ext, $documentTypes)) return 'document';
        if (in_array($ext, $archiveTypes)) return 'archive';
        if (in_array($ext, $audioTypes)) return 'audio';
        if (in_array($ext, $videoTypes)) return 'video';
        return 'file';
    }

    private function formatSize($bytes) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}