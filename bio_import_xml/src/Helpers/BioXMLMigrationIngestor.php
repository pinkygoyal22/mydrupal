<?php
namespace Drupal\bio_import_xml\Helpers;

use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigBase;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use \Psr\Log\LoggerInterface;


class BioXMLMigrationIngestor {

  /**
   * @var string $module The name of the module.
   */
  protected $module = 'bio_import_xml';

  /**
   * @var string $storageTable The name of the target table.
   */
  protected $storageTable = 'migrate_thm_storage';

  /**
   * @var bool $reset A flag that marks incoming records as new if true.
   */
  protected $reset;

  /**
   * @var Connection $db A Drupal database connection.
   */
  protected $db;

  /**
   * @var array $data
   */
  protected $data = [
    'record_count' => [ 'inserted' => 0, 'updated' => 0 ],
    'to_insert' => [
      'fields' => [], 'values' => []
    ],
    'to_update' => [],
  ];
  /**
   * @var MessengerInterface
   */
  private $messenger;


  public function isAnExistingHistoryMakersID($key, $val) {
    return (is_int($key) && isset($val));
  }

  public function isAValidHistoryMakersID($val) {
    return strlen($val) >= 5;
  }

  public function isAValidAccessionNumber($val) {
    return strlen($val) > 8;
  }

  public function isDateLessThanAWeekOld($val) {
    return (time() - $val) < (86400 * 7);
  }

  public function getNidFromHmidDataField(Connection $db, $hmId) {
    $stmt = "SELECT entity_id FROM {node__field_hm_id} hmid INNER JOIN
    node n on (hmid.entity_id = n.nid) WHERE field_hm_id_value = :hm_id";

    try {
      return $db->query($stmt, [':hm_id' => $hmId])->fetchField();
    } catch (DatabaseExceptionWrapper $dbExc) {
      $this->messenger->addMessage($dbExc->getMessage());
    }

    return false;
  }

  public function prepareTable(Connection $db) {
    $fieldLocFlashTitle = 'location_flash_title';
    $schema = $db->schema();

    if (!$schema->fieldExists($this->storageTable, $fieldLocFlashTitle)) {
      $schema->dropTable($this->storageTable);
      $this->messenger->addMessage("Flushing table: $this->storageTable");
    }

    if (!$schema->tableExists($this->storageTable)) {
      $notFoundMsg = "Table: $this->storageTable not found. Creating.";
      $this->messenger->addMessage($notFoundMsg);
      \drupal_install_schema('bio_import_xml');
    }

    return ($schema->tableExists($this->storageTable) &&
        $schema->fieldExists($this->storageTable, $fieldLocFlashTitle));
  }

  public function loadXml(ConfigBase $config) {
    $module = $this->module;
    $pathToXml = $config->get($module . '.fm_path');
    $xmlFile = $pathToXml . $config->get($module . '.clean');

    try {
      $xml = new \SplFileObject($xmlFile);
      return $xml->fread($xml->getSize());
    } catch (\RuntimeException $exc) {
      $this->messenger->addMessage('failed to load xml file.');
      $this->messenger->addMessage($exc->getMessage());
    }

    return false;
  }

  public function convertXml2Array(ConfigBase $config) {
    $xmlData = $this->loadXml($config);

    if ($xmlData === false) {
      $this->messenger->addMessage('The XML data could not be loaded.');
      return false;
    } else {
      return BioXMLMigrationHelpers::xml2array($xmlData);
    }
  }

  public function isInFieldList($value, $arr) {
    return in_array($value, $arr);
  }

  /**
   * Converts a datetime string into a unix timestamp.
   *
   * @param string $dtValue A string formatted DateTime value.
   * @return false|int A UNIX timestamp.
   */
  public function convertTimeStamp($dtValue) {
    $d = explode(' ', $dtValue);
    $date = array_shift($d);
    $dateParts = explode('/', $date);

    return mktime(
      0, 0, 0,
      intval($dateParts[0]), intval($dateParts[1]), intval($dateParts[2]));
  }

  public function processBiography($bio, &$data) {
    $done = '';

    foreach ($bio as $field => $value) {
      if ($field === 'SponsorLogo' && is_string($value)) {
        if (substr($value, 0, 5) === 'size:') {
          $spl = explode('imagewin:', $value);
          $value = 'imagewin:' . $spl[1];
        }
      }

      if (strpos($done, $field) === false) {
        if (is_array($value)) {
          $newValue = $value;
          unset($value);
          $value = implode(' -$- ', $newValue);
        }
        // add logic for favorites here
        $v = trim($value);
        if (strlen($v) || (substr(strtolower($field),0,9)=='favorite_')) {
          if (!$this->isInFieldList($v, $data['to_insert']['fields'])) {
            $data['to_insert']['fields'][] = strtolower($field);
            $data['to_insert']['values'][] = addslashes(urldecode($v));
          }
          $data['to_update'][strtolower($field)] = addslashes(urldecode($v));
        }
      }

      $done = ' - ' . $field;
    }
  }

  public function processBiographies($biographies, LoggerInterface $logger) {
      $messenger = \Drupal::messenger();
      $action = null;
      $data = [
          'to_insert' => [ 'fields' => [], 'values' => [] ],
          'to_update' => []
      ];

      foreach ($biographies as $key => $bio) {

        $hmId = (isset($bio['HM_ID'])) ? $bio['HM_ID'] : '';



        if (!$this->isAValidHistoryMakersID($hmId)) {
          $messenger->addMessage('skipping ' . $hmId . ' as it is not valid.');
          continue;
        }

        if (!$this->isAValidAccessionNumber($bio['Accession'])) {
          $logger->notice('Skipping ' . $hmId . ' as it has no valid accession number.');
          continue;
        }

        if (isset($bio['TimeStampModificationAny'])) {
          $newDate = $this->convertTimeStamp($bio['TimeStampModificationAny']);

          $data['to_insert']['fields'][] = 'timestamp';
          $data['to_insert']['values'][] = $newDate;
          $data['to_update']['timestamp'] = $newDate;
        }

        if ($this->reset || (isset($newDate) && $this->isDateLessThanAWeekOld($newDate))) {
          $data['to_insert']['fields'][] = 'new';
          $data['to_insert']['values'][] = '1';
          $data['to_update']['new'] = '1';
          //$this->data['to_update'][] = "new = '1' ";

          if (!$this->isAnExistingHistoryMakersID($key, $bio['NameFirst'])) {
            $messenger->addMessage('Missing HM fields: ' . $hmId);
          } else {
            $nid = $this->getNidFromHmidDataField($this->db, $hmId);

            $bioCount = BioXMLMigrationHelpers::historyMakerExists($this->db, $hmId);
            $action = ($bioCount != 0) ? 'update' : 'insert';

            if ($nid) {
              $data['to_insert']['fields'][] = 'nid';
              $data['to_insert']['values'][] = $nid;
              $data['to_update']['nid'] = $nid;
            }
          }
        }

        $this->processBiography($bio, $data);

        $this->upsert($this->db, $action, $hmId, $data);
        $data['to_insert']['fields'] = array();
        $data['to_insert']['values'] = array();
        $data['to_update'] = array();
      }
  }

  public function upsert(Connection $db, $action, $hmId, $data) {
    $messenger = \Drupal::messenger();
    $messenger->addMessage("attempting to $action record: $hmId");
    switch ($action) {
      case 'update':
        $db->update($this->storageTable)
            ->fields($data['to_update'])
            ->condition('hm_id', $hmId)
            ->execute();
        return true;
      case 'insert':
        $db->insert($this->storageTable)
            ->fields($data['to_insert']['fields'])
            ->values($data['to_insert']['values'])
            ->execute();
        return true;
      default:
        $messenger->addMessage('record fell through.');
        return false;
    }
  }

  public function __construct(Connection $db, $reset) {
    $this->db = $db;
    $this->reset = $reset;
    $this->messenger = \Drupal::messenger();
  }

  public static function ingest(Connection $db, ConfigBase $config, $reset = false) {
    $messenger = \Drupal::messenger();
    $instance = new self($db, $reset);

    $prepared = $instance->prepareTable($instance->db);

    if (!$prepared) {
      $messenger->addMessage('table check test failed. exiting.');
      return false;
    } else {
      $messenger->addMessage('Ready to begin ingest process.');
      $xmlArray = $instance->convertXml2Array($config);
      $bios = $xmlArray['bios']['Bio'];

      $instance->processBiographies($bios, \Drupal::logger($instance->module));
      return true;
    }
  }
}
