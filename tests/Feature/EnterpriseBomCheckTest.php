<?php

namespace Tests\Feature;

use Tests\TestCase;

class EnterpriseBomCheckTest extends TestCase
{
    /**
     * Ensure no PHP file in app, database, routes, or config contains a UTF-8 BOM.
     * UTF-8 BOM (\xef\xbb\xbf) breaks PHP namespace declarations causing HTTP 500 FatalError.
     */
    public function test_no_php_files_contain_utf8_bom(): void
    {
        $baseDirs = [
            app_path(),
            database_path(),
            base_path('routes'),
            config_path(),
        ];

        $bomFiles = [];

        foreach ($baseDirs as $dir) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $handle = fopen($file->getRealPath(), 'rb');
                    if ($handle) {
                        $header = fread($handle, 3);
                        fclose($handle);

                        if ($header === "\xef\xbb\xbf") {
                            $bomFiles[] = $file->getRealPath();
                        }
                    }
                }
            }
        }

        $this->assertEmpty(
            $bomFiles,
            "The following PHP files contain an illegal UTF-8 BOM that causes FatalError on line 3:\n" . implode("\n", $bomFiles)
        );
    }
}
