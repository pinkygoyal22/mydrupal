<?php


namespace Drupal\bio_import_xml\Helpers;
ini_set('memory_limit', '4G');

use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Config\ConfigBase;
use Drupal\file\Entity\File;
use Drupal\Component\Utility\Unicode;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\File\FileSystemInterface;

class BioXMLMigrationHelpers {

  public static $storageFieldNames = 'HM_ID, nid, Accession, BirthCity,
       BirthState, BirthCountry,
       DateBirth, Dates_of_Sessions, DateDeath, MaritalStatus, Gender,
       Favorite_Color, Favorite_Food, Favorite_Quote, Favorite_Season,
       Favorite_VacationSpot, PreferredName, NameFirst, NameMiddle, NameLast,
       BiographyLong, DescriptionShort, Category, Location_Flash_File,
       Location_Flash_Title, Employment_for, Occupation, OccupationCategories,
       Organizations, Sponsor, Schools_for, BiographyLongWords, ImageBio,
       ImageArchive01, ImageArchive02, BiographyLongPath, SpeakersBureauYesNo,
       SpeakersBureauPreferredAudience, SpeakersBureauHonorarium,
       SpeakersBureauAvailability, SpeakersBureauNotes, RegionCity, RegionState,
       TimeStampModificationAny, SponsorLogo, SponsorURL, InterviewPDF1,
       InterviewPDF2, LinkToTHMDA, LinkToSMDA, DAStoryList, DASession, DACaption,
       DATape, DATitle, DAUrl, DATIMINGPAIR, new, timestamp, indl';

  public function __construct() {
    $this->messenger = \Drupal::messenger();
  }

  public static function jsonifyTimingPairs($data) {

    return json_encode([ 'timingPairs' => array_map(function($pair) {
      $values = explode(',', $pair);
      return [ 'offset' => $values[0], 'time' => $values[1] ];
    }, explode(':', urldecode($data)))]);
  }

  public static function getTags($tagClump, $vocabName) {
    $tags = explode('$', $tagClump);

    if (count($tags) && strlen(trim($tags[0]))) {
      return array_map(function($tag) use ($vocabName) {

        if ($vocabName == 'tags') {
          $t = explode(' - ', $tag);

          if (is_numeric($t[1] && $t >= 3)) {
            return [ 'target_id' => self::getTid($t[0], $vocabName)];
          }
        } else {
          $tid = self::getTid($tag, $vocabName);

          if ($tid && is_numeric($tid)) return [ 'target_id' => $tid ];
        }
      }, $tags);
    } else {
      return [];
    }
  }

    public static function getTid($term, $vocabName) {
        $output = false;
        $termArray = \taxonomy_term_load_multiple_by_name(
          trim($term), $vocabName);


        if (count($termArray)) {
            $ks = array_keys($termArray);
            $tid = $ks[0];
            $output = $tid;

            if (!$termArray[$tid]->vocabulary_machine_name != $vocabName) {
                if (function_exists('dsm')) {
                    $x = $termArray[$tid]->vocabulary_machine_name;
                    $msg = "$term exists in $x but not in $vocabName";
                    dsm($msg);
                }
                $output = false;
            }

            if ($vocabName === 'tags' && strpos($term, ' - ')) {
                $tx = \Drupal::entityTypeManager()
                        ->getStorage($vocabName);
                $term = $tx->load($tid);
                $messenger = \Drupal::messenger();
                $messenger->addMessage('attempting to delete: ' . print_r($term, true));
                $tx->delete($term);

                taxonomy_taxonomy_term_delete($tid);
                if (function_exists('dsm')) dsm('Removed :' . $term);
                $output = false;
            }
        }

        if ($vocabName === 'tags' && strpos($term, ' - ')) {
          $parts = explode(' - ', $term);
          $count = $parts[1];
          if (is_numeric($count) && $count >= 3) {
              $term = $parts[0];
              $output = false;
          } else {
              return false;
          }
        }

        if ($output === false && strlen(trim($term))) {
          //$vs = \taxonomy_vocabulary_get_names();

          $t = Term::create([
            'name' => $term,
            'vid' => $vocabName,
          ]);
          $t->save();

          if (function_exists('dsm')) {
            dsm('Added: ' . $t->getName() . ' to ' . $vocabName);
          }

          $output = $t->get('tid');
        }

        return $output;

    }

  /**
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public static function attachImage(Connection $db, ConfigBase $config, $path) {
        if (strlen(trim($path)) === 0) return false;

        $fmPath = $config->get('bio_import_xml.fm_files_path');
        $importErrorKey = 'bio_import_xml.image_import_errors';
        $pathArray = explode('/', $path);
        $pathToFile = end($pathArray);
        $extensionArray = explode('.', $path);
        $extension = end($extensionArray);
        $stmt = "SELECT fid FROM {file_managed} WHERE uri = :uri";
        $uri = 'public://' . preg_replace('/[^\w\-.]/', '', $pathToFile);

        $result = $db->query($stmt, [':uri' => $uri]);
        $fid = $result->fetchCol();

        $action = 'add';

        if ($action === 'update') {
            $file = File::load($fid[0]);

            if (strlen($file->getFilename())) {
                return $file;
            }
        } else {
            $dir = [
                'imagewin:/H:/HM Interviews/',
                'imagewin:/I:/',
                'H:/HM Interviews/'
            ];

            $filePath = $fmPath . str_replace($dir, '', trim($path));
            if (file_exists($filePath)) {
                $fileContents = file_get_contents($filePath);
                $f = file_save_data($fileContents, 'public://'.self::stripInvalidXml($pathToFile), FileSystemInterface::EXISTS_REPLACE);
                if($f) { $name = $f->getFilename(); }
                if (isset($name) && strlen($name)) {
                  return $f;
                }
            } else {
              self::addToErrorStateObject($filePath . ' doesn\'t exist.');
              // TODO: Move $placeholderUrl to config or admin form.
              if ($extension != 'pdf') {
                $placeholderUrl = 'https://via.placeholder.com/300x300';
                return self::attachPlaceholderImage($placeholderUrl);
              }
            }
        }

        return false;
    }

    public static function addToErrorStateObject($msg) {
      $imageErrorStateKey = 'bio_import_xml.image_import_errors';
      $errors = \Drupal::state()->get($imageErrorStateKey);
      array_push($errors, $msg);
      \Drupal::state()->set($imageErrorStateKey, $errors);
    }

    public static function attachPlaceholderImage($url) {

      $file = \system_retrieve_file($url, null, true, FileSystemInterface::EXISTS_REPLACE);

      if (!$file) {
        // TODO: Add logger as ; log that online placeholder
        //       could not be retrieved.

        $messenger = \Drupal::messenger();
        $messenger->addMessage("the file at $url could not be retrieved.");
      } else {
        $file->setFilename('placeholder.png'); // is this working?

        return $file;
      }
    }

    public static function migrateThmExplode($delimiter, $clump, $charLimit = 240) {

        $q = explode($delimiter, $clump);

        return array_map(function($ele) use ($charLimit) {
            if (strlen(trim($ele)) >= 2) {
                return trim(
                    Unicode::truncate(
                        $ele, $charLimit,
                        240, 240, 1));
            }
        }, $q);
    }

    public static function historyMakerExists(Connection $db, $hmId) {
        $stmt = "SELECT COUNT(hm_id) FROM {migrate_thm_storage} WHERE hm_id = :hm_id";
        $returnValue = $db->query($stmt, [ ':hm_id' => $hmId ])->fetchField();
        return $returnValue;
    }

    public static function xml2array($contents, $get_attributes=1, $priority = 'tag') {
        if(!$contents) return array();

        if(!function_exists('xml_parser_create')) {
            //print "'xml_parser_create()' function not found!";
            return array();
        }

        //Get the XML parser of PHP - PHP must have this module for the parser to work
        $parser = xml_parser_create('');
        xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, "UTF-8"); # http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss
        xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
        xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);
        xml_parse_into_struct($parser, trim($contents), $xml_values);
        xml_parser_free($parser);

        if(!$xml_values) return;//Hmm...

        //Initializations
        $xml_array = array();
        $parents = array();
        $opened_tags = array();
        $arr = array();

        $current = &$xml_array; //Refference

        //Go through the tags.
        $repeated_tag_index = array();//Multiple tags with same name will be turned into an array
        foreach($xml_values as $data) {
            unset($attributes,$value);//Remove existing values, or there will be trouble

            //This command will extract these variables into the foreach scope
            // tag(string), type(string), level(int), attributes(array).
            extract($data);//We could use the array by itself, but this cooler.

            $result = array();
            $attributes_data = array();

            if(isset($value)) {
                if($priority == 'tag') $result = $value;
                else $result['value'] = $value; //Put the value in a assoc array if we are in the 'Attribute' mode
            }

            //Set the attributes too.
            if(isset($attributes) and $get_attributes) {
                foreach($attributes as $attr => $val) {
                    if($priority == 'tag') $attributes_data[$attr] = $val;
                    else $result['attr'][$attr] = $val; //Set all the attributes in a array called 'attr'
                }
            }

            //See tag status and do the needed.
            if($type == "open") {//The starting of the tag '<tag>'
                $parent[$level-1] = &$current;
                if(!is_array($current) or (!in_array($tag, array_keys($current)))) { //Insert New tag
                    $current[$tag] = $result;
                    if($attributes_data) $current[$tag. '_attr'] = $attributes_data;
                    $repeated_tag_index[$tag.'_'.$level] = 1;

                    $current = &$current[$tag];

                } else { //There was another element with the same tag name

                    if(isset($current[$tag][0])) {//If there is a 0th element it is already an array
                        $current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;
                        $repeated_tag_index[$tag.'_'.$level]++;
                    } else {//This section will make the value an array if multiple tags with the same name appear together
                        $current[$tag] = array($current[$tag],$result);//This will combine the existing item and the new item together to make an array
                        $repeated_tag_index[$tag.'_'.$level] = 2;

                        if(isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well
                            $current[$tag]['0_attr'] = $current[$tag.'_attr'];
                            unset($current[$tag.'_attr']);
                        }

                    }
                    $last_item_index = $repeated_tag_index[$tag.'_'.$level]-1;
                    $current = &$current[$tag][$last_item_index];
                }

            } elseif($type == "complete") { //Tags that ends in 1 line '<tag />'
                //See if the key is already taken.
                if(!isset($current[$tag])) { //New Key
                    $current[$tag] = $result;
                    $repeated_tag_index[$tag.'_'.$level] = 1;
                    if($priority == 'tag' and $attributes_data) $current[$tag. '_attr'] = $attributes_data;

                } else { //If taken, put all things inside a list(array)
                    if(isset($current[$tag][0]) and is_array($current[$tag])) {//If it is already an array...

                        // ...push the new element into that array.
                        $current[$tag][$repeated_tag_index[$tag.'_'.$level]] = $result;

                        if($priority == 'tag' and $get_attributes and $attributes_data) {
                            $current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
                        }
                        $repeated_tag_index[$tag.'_'.$level]++;

                    } else { //If it is not an array...
                        $current[$tag] = array($current[$tag],$result); //...Make it an array using using the existing value and the new value
                        $repeated_tag_index[$tag.'_'.$level] = 1;
                        if($priority == 'tag' and $get_attributes) {
                            if(isset($current[$tag.'_attr'])) { //The attribute of the last(0th) tag must be moved as well

                                $current[$tag]['0_attr'] = $current[$tag.'_attr'];
                                unset($current[$tag.'_attr']);
                            }

                            if($attributes_data) {
                                $current[$tag][$repeated_tag_index[$tag.'_'.$level] . '_attr'] = $attributes_data;
                            }
                        }
                        $repeated_tag_index[$tag.'_'.$level]++; //0 and 1 index is already taken
                    }
                }

            } elseif($type == 'close') { //End of tag '</tag>'
                $current = &$parent[$level-1];
            }
        }

        return($xml_array);
    }

    public static function stripInvalidXml($value) {
        $ret = "";
        $current = null;

        if (empty($value)) {
            return $ret;
        }

        $length = strlen($value);
        for ($i=0; $i < $length; $i++) {
            $current = ord($value{$i});
            if (($current == 0x9) ||
                ($current == 0xA) ||
                ($current == 0xD) ||
                (($current >= 0x20) && ($current <= 0xD7FF)) ||
                (($current >= 0xE000) && ($current <= 0xFFFD)) ||
                (($current >= 0x10000) && ($current <= 0x10FFFF)))
            {
                $ret .= chr($current);
            }
            else {
                $ret .= " ";
            }
        }

        $ret = str_replace('&', ' |and| ', $ret);
        $ret = mb_convert_encoding($ret, 'UTF-8', 'HTML-ENTITIES');

        return $ret;
    }
}
