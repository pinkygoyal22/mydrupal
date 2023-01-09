<?php

namespace Drupal\open_api_nodes\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\file\Entity\File;
use Drupal\file\Plugin\Field\FieldFormatter\FileFormatterBase;
use Drupal\Core\Field\FormatterInterface;
/*use Symfony\Component\DependencyInjection\ContainerInterface;*/

/**
 * Plugin implementation of Swagger UI file field formatter.
 *
 * @FieldFormatter(
 *   id = "swagger_ui_file",
 *   label = @Translation("Swagger UI"),
 *   field_types = {
 *     "file"
 *   }
 * )
 */
class OpenApiNodesFileFieldFormatter extends FileFormatterBase implements ContainerFactoryPluginInterface {
  /**
   * Builds a render array from a field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface $items
   *   Field items.
   * @param \Drupal\Core\Field\FormatterInterface $formatter
   *   The current field formatter.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition in the current field formatter.
   * @param array $context
   *   Additional context for field rendering.
   *
   * @return array
   *   Field value as a render array.
   */
  final protected function buildRenderArray(FieldItemListInterface $items, FormatterInterface $formatter, FieldDefinitionInterface $field_definition, array $context = []): array {
    $element = [];
    $library_name = 'open_api_nodes.swagger_ui_integration';
    
	$library_dir = '\libraries';
	
    // Set the oauth2-redirect.html file path for OAuth2 authentication.
    $oauth2_redirect_url = \Drupal::request()->getSchemeAndHttpHost() . '/' . $library_dir . '/dist/oauth2-redirect.html';

      foreach ($items as $delta => $item) {
        $element[$delta] = [
          '#delta' => $delta,
          '#field_name' => $field_definition->getName(),
        ];

        $oas_file_url = $this->getOpenAPIFileUrlFromField($item, $context + ['field_items' => $items]);
        if ($oas_file_url === NULL) {
          $element[$delta] += [
            '#theme' => 'status_messages',
            '#message_list' => [
              'error' => [$this->t('Could not create URL to file.')],
            ],
          ];
        }
        else {
          $element[$delta] += [
            '#theme' => 'open_api_nodes_field_item',
            '#attached' => [
              'library' => [
                'open_api_nodes/' . $library_name,
              ],
              'drupalSettings' => [
                'openAPINodesSwaggerUIFormatter' => [
                  "{$field_definition->getName()}-{$delta}" => [
                    //'oauth2RedirectUrl' => $oauth2_redirect_url,
                    'oasFile' => $oas_file_url,
                    'validator' => $formatter->getSetting('validator'),
                    //'validatorUrl' => $formatter->getSetting('validator_url'),
                    'docExpansion' => $formatter->getSetting('doc_expansion'),
                    'showTopBar' => 1,
                    //'sortTagsByName' => $formatter->getSetting('sort_tags_by_name'),
                    //'supportedSubmitMethods' => array_keys(array_filter($formatter->getSetting('supported_submit_methods'))),
                  ],
                ],
              ],
            ],
          ];
        }
      }

    /*if ($swagger_ui_library_discovery instanceof CacheableDependencyInterface) {
      $cacheable_metadata = CacheableMetadata::createFromRenderArray($element)->merge(CacheableMetadata::createFromObject($swagger_ui_library_discovery));
      $cacheable_metadata->applyTo($element);
    }*/

    return $element;
  }


  /**
   * {@inheritdoc}
   */
  protected function getOpenAPIFileUrlFromField(FieldItemInterface $field_item, array $context = []): ?string {
    if (!isset($this->fileEntityCache[$context['field_items']->getEntity()->id()])) {
      // Store file entities keyed by their id.
      $this->fileEntityCache[$context['field_items']->getEntity()->id()] = array_reduce($this->getEntitiesToView($context['field_items'], $context['lang_code']), static function (array $carry, File $entity) {
        $carry[$entity->id()] = $entity;
        return $carry;
      }, []);
    }

    // This is only set if the file entity exists and the current user has
    // access to the entity.
    if (isset($this->fileEntityCache[$context['field_items']->getEntity()->id()][$field_item->getValue()['target_id']])) {
      /** @var \Drupal\file\Entity\File $file */
      $file = $this->fileEntityCache[$context['field_items']->getEntity()->id()][$field_item->getValue()['target_id']];
      $url = file_create_url($file->getFileUri());
      if ($url === FALSE) {
        $this->logger->error('URL could not be created for %file file.', [
          '%file' => $file->label(),
          'link' => $context['field_items']->getEntity()->toLink($this->t('view'))->toString(),
        ]);
        return NULL;
      }

      return $url;
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    return $this->buildRenderArray($items, $this, $this->fieldDefinition, ['lang_code' => $langcode]);
  }

}
