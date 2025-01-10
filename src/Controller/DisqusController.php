<?php

namespace Drupal\disqus\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Defines the controller for closing window.
 */
class DisqusController extends ControllerBase {

  /**
   * Menu callback; Automatically closes the window after the user logs in.
   *
   * @return array
   *   A render array containing the confirmation message and link that closes
   *   overlay window.
   */
  public function closeWindow() {
    $build = [];
    $build['#markup'] = $this->t('Thank you for logging in. Please close this window, or <a href="@clickhere">click here</a> to continue.',
      ['@clickhere' => 'javascript:window.close();']);
    $build['#attached']['html_head'][] = [[
      '#tag' => 'script',
      '#value' => 'window.close();',
    ], 'disqus_js',
    ];
    return $build;
  }

}
