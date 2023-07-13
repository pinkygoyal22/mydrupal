<?php

namespace Drupal\bio_import_xml\Helpers;

use Drupal\Core\Config\ConfigBase;
use Drush\Drush;

/**
 * Class BioXMLMigrationCleaner
 * @package Drupal\bio_import_xml\Helpers
 */
class BioXMLMigrationCleaner {

    /**
     * @var string $module The name of the module.
     */
    public static $module = 'bio_import_xml';

    /**
     * Removes illegal characters from an XML Document.
     *
     * Creates a new "cleaned" XML Document with illegal characters removed.
     *
     * @param $config ConfigBase
     * @return bool
     */
    public static function clean(ConfigBase $config) {
        $module = self::$module;
        $pathToXml = $config->get($module . '.fm_path');
        $xmlFile   = $pathToXml . $config->get($module . '.original');
        $cleanXml  = $pathToXml . $config->get($module . '.clean');

        try {
            $xml  = new \SplFileObject($xmlFile);
            $data = $xml->fread($xml->getSize());
        } catch (\RuntimeException $exc) {
          Drush::output()->writeln('file not found: ' . $exc->getMessage());
          return false;
        }


        $handle   = fopen($cleanXml, "w+");
        $outgoing = BioXMLMigrationHelpers::stripInvalidXml($data);

        fwrite($handle, str_replace('<Bio id', "\n" . '<Bio id', $outgoing));
        return fclose($handle);
    }
}