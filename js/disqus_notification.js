/**
 * @file
 * JavaScript for the Disqus module's new comment notification.
 */

  (function ($) {

    "use strict";

    /**
     * Notify of new comments.
     */
    Drupal.disqus.disqusNotifyNewComment = function (comment) {
      $.post({
        url: drupalSettings.path.baseUrl + drupalSettings.path.pathPrefix + 'disqus/new-comment/' + comment.id,
        data: {},
        dataType: 'json',
      });
    };

  })(jQuery, Drupal, drupalSettings);
