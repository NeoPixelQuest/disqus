<?php

namespace Drupal\disqus\Element;

use Drupal\Core\Render\Element\RenderElement;

/**
 * Provides Disqus render element.
 *
 * @RenderElement("disqus")
 */
class Disqus extends RenderElement {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    return [
      '#title' => '',
      '#url' => '',
      '#identifier' => '',
      '#callbacks' => [],
      '#attributes' => ['id' => 'disqus_thread'],
      '#pre_render' => [
        [static::class, 'generatePlaceholder'],
      ],
    ];
  }

  /**
   * Pre_render callback to generate a placeholder.
   *
   * @param array $element
   *   A renderable array.
   *
   * @return array
   *   The updated renderable array containing the placeholder.
   */
  public static function generatePlaceholder(array $element) {
    if (\Drupal::currentUser()->hasPermission('view disqus comments')) {
      $element[] = [
        '#lazy_builder' => [
          static::class . '::displayDisqusComments',
          [
            $element['#title'],
            $element['#url'],
            $element['#identifier'],
            serialize($element['#callbacks']),
          ],
        ],
        '#create_placeholder' => TRUE,
      ];
    }

    return $element;
  }

  /**
   * Post render function of the Disqus element to inject the Disqus JavaScript.
   */
  public static function displayDisqusComments($title, $url, $identifier, $callbacks) {
    $disqus_settings = \Drupal::config('disqus.settings');
    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = \Drupal::service('renderer');
    $element = [
      '#theme_wrappers' => ['disqus_noscript', 'container'],
      '#attributes' => ['id' => 'disqus_thread'],
    ];
    $renderer->addCacheableDependency($element, $disqus_settings);

    $disqus = [
      'domain' => $disqus_settings->get('disqus_domain'),
      'url' => $url,
      'title' => $title,
      'identifier' => $identifier,
    ];

    // If the user is logged in, we can inject the username and email for
    // Disqus.
    $account = \Drupal::currentUser();
    if ($disqus_settings->get('behavior.disqus_inherit_login') && !$account->isAnonymous()) {
      $renderer->addCacheableDependency($element, $account);
      $disqus['name'] = $account->getAccountName();
      $disqus['email'] = $account->getEmail();
    }

    // Provide alternate language support if desired.
    if ($disqus_settings->get('behavior.disqus_localization')) {
      $disqus['language'] = \Drupal::languageManager()->getCurrentLanguage()->getId();
    }

    // Check if we are to provide Single Sign-On access.
    if ($disqus_settings->get('advanced.sso.disqus_sso')) {
      $disqus += \Drupal::service('disqus.manager')->ssoSettings();
    }

    // Pass callbacks on if needed. Callbacks array is two dimensional array
    // with callback type as key on first level and array of JS callbacks on the
    // second level.
    // Example:
    // @code
    // $element = [
    //   '#type' => 'disqus',
    //   '#callbacks' = [
    //     'onNewComment' => [
    //       'myCallbackThatFiresOnCommentPost',
    //       'Drupal.mymodule.anotherCallbInsideDrupalObj',
    //     ],
    //   ],
    // ];
    // @endcode
    $callbacks = unserialize($callbacks);
    if (!empty($callbacks)) {
      $disqus['callbacks'] = $callbacks;
    }
    // Check if we want to track new comments in Google Analytics.
    if ($disqus_settings->get('behavior.disqus_track_newcomment_ga')) {
      // Add a callback when a new comment is posted.
      $disqus['callbacks']['onNewComment'][] = 'Drupal.disqus.disqusTrackNewComment';
      // Attach the js with the callback implementation.
      $element['#attached']['library'][] = 'disqus/ga';
    }
    // Check if we want to notify of new comments. This can only work if a
    // secret key has been set for API requests.
    if ($disqus_settings->get('advanced.disqus_secretkey') && $disqus_settings->get('behavior.disqus_notify_newcomment')) {
      // Add a callback when a new comment is posted.
      $disqus['callbacks']['onNewComment'][] = 'Drupal.disqus.disqusNotifyNewComment';
      // Attach the js with the callback implementation.
      $element['#attached']['library'][] = 'disqus/notification';
    }
    // Add the disqus.js and all the settings to process the JavaScript and load
    // Disqus.
    $element['#attached']['library'][] = 'disqus/disqus';
    $element['#attached']['drupalSettings']['disqus'] = $disqus;

    return $element;
  }

}
