/**
 * @file
 * Custom scripts to render file fields with Swagger UI.
 */

(function ($, window, Drupal, drupalSettings) {

  Drupal.behaviors.openAPINodesSwaggerUIFormatter = {
    attach: function (context) {
      // Iterate over field values and render each field value with Swagger UI.
      for (var fieldNamePlusDelta in drupalSettings.openAPINodesSwaggerUIFormatter) {
        if (drupalSettings.openAPINodesSwaggerUIFormatter.hasOwnProperty(fieldNamePlusDelta)) {
          var fieldElementInField = drupalSettings.openAPINodesSwaggerUIFormatter[fieldNamePlusDelta];

          if ('swagger_ui' in window) {
            continue;
          }

          var validatorUrl = undefined;
          switch (fieldElementInField.validator) {
            case 'custom':
              validatorUrl = fieldElementInField.validatorUrl;
              break;

            case 'none':
              validatorUrl = null;
              break;
          }

          var options = {
            url: fieldElementInField.oasFile,
            dom_id: '#swagger-ui',
          };

          window['swagger_ui'] = SwaggerUIBundle(options);
        }
      }
    }
  };

}(jQuery, window, Drupal, drupalSettings));
