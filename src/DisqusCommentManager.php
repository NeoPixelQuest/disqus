<?php

namespace Drupal\disqus;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * It contains common functions to manage disqus_comment fields.
 */
class DisqusCommentManager implements DisqusCommentManagerInterface {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * Configuration Factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * Constructs the DisqusCommentManager object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   A module handler.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   A module handler.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, AccountInterface $current_user, ModuleHandlerInterface $module_handler, ConfigFactory $config_factory, FileUrlGeneratorInterface $file_url_generator = NULL) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->currentUser = $current_user;
    $this->moduleHandler = $module_handler;
    $this->configFactory = $config_factory;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public function getFields($entity_type_id) {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    if (!$entity_type->entityClassImplements('\Drupal\Core\Entity\ContentEntityInterface')) {
      return [];
    }

    $map = $this->getAllFields();
    return isset($map[$entity_type_id]) ? $map[$entity_type_id] : [];
  }

  /**
   * {@inheritdoc}
   */
  public function getAllFields() {
    $map = $this->entityFieldManager->getFieldMap();
    // Build a list of disqus comment fields only.
    $disqus_comment_fields = [];
    foreach ($map as $entity_type => $data) {
      foreach ($data as $field_name => $field_info) {
        if ($field_info['type'] == 'disqus_comment') {
          $disqus_comment_fields[$entity_type][$field_name] = $field_info;
        }
      }
    }
    return $disqus_comment_fields;
  }

  /**
   * {@inheritdoc}
   */
  public function ssoSettings() {

    $disqus['sso'] = [
      'name' => $this->configFactory->get('system.site')->get('name'),
      // The login window must be closed once the user logs in.
      'url' => Url::fromRoute('user.login', [], [
        'query' => [
          'destination' => Url::fromRoute('disqus.close_window')->toString(),
        ],
        'absolute' => TRUE,
      ]
      )->toString(),
      // The logout link must redirect back to the original page.
      'logout' => Url::fromRoute('user.logout', [], [
        'query' => [
          'destination' => Url::fromRoute('<current>')->toString(),
        ],
        'absolute' => TRUE,
      ]
      )->toString(),
      'width' => 800,
      'height' => 600,
    ];

    $managed_logo = $this->configFactory->get('disqus.settings')->get('advanced.sso.disqus_logo');
    $use_site_logo = $this->configFactory->get('disqus.settings')->get('advanced.sso.disqus_use_site_logo');
    if (!$use_site_logo && !empty($managed_logo)) {
      $disqus['sso']['button'] = $this->entityTypeManager->getStorage('file')->load($managed_logo)->createFileUrl();
    }
    elseif ($logo = theme_get_setting('logo')) {
      $url = $logo['url'];
      if (!UrlHelper::isExternal($url)) {
        $url = Url::fromUri('internal:' . $url, ['absolute' => TRUE])->toString();
      }
      $disqus['sso']['button'] = $url;
    }
    else {
      $disqus['sso']['button'] = Url::fromUri('base://core/misc/druplicon.png', ['absolute' => TRUE])->toString();
    }
    if ($favicon = theme_get_setting('favicon')) {
      $disqus['sso']['icon'] = $favicon['url'];
    }

    // Stick the authentication requirements and data in the settings.
    $disqus['api_key'] = $this->configFactory->get('disqus.settings')->get('advanced.disqus_publickey');
    $disqus['remote_auth_s3'] = $this->ssoKeyEncode($this->ssoUserData());

    return $disqus;
  }

  /**
   * Assembles the full private key for use in SSO authentication.
   *
   * @param array $data
   *   An array contating data.
   *
   * @return string
   *   The String containing message, timestamp.
   */
  protected function ssoKeyEncode(array $data) {
    // Encode the data to be sent off to Disqus.
    $message = base64_encode(json_encode($data));
    $timestamp = time();
    $hmac = hash_hmac('sha1', "$message $timestamp", $this->configFactory->get('disqus.settings')->get('advanced.disqus_secretkey'));

    return "$message $hmac $timestamp";
  }

  /**
   * Assembles user-specific data used by Disqus SSO.
   *
   * @return array
   *   An array containing sso user data.
   */
  protected function ssoUserData() {
    $account = $this->currentUser;
    $data = [];
    if (!$account->isAnonymous()) {
      $data['id'] = $account->id();
      $data['username'] = $account->getAccountName();
      $data['email'] = $account->getEmail();
      $data['url'] = Url::fromRoute('entity.user.canonical', ['user' => $account->id()], ['absolute' => TRUE])->toString();

      // Load the user's avatar.
      $user_picture_default = $this->configFactory->get('field.instance.user.user.user_picture')->get('settings.default_image');

      $user = $this->entityTypeManager->getStorage('user')->load($account->id());
      if (isset($user->user_picture->target_id) && !empty($user->user_picture->isEmpty()) && $file = $this->entityTypeManager->getStorage('file')->load($user->user_picture->entity->getFileUri())) {
        $file_uri = $file->getFileUri();
        $data['avatar'] = !empty($file_uri) ? $file_uri : NULL;
      }
      elseif (!empty($user_picture_default['fid']) && $file = $this->entityTypeManager->getStorage('file')->load($user_picture_default['fid'])) {
        $file_uri = $file->getFileUri();
        $data['avatar'] = !empty($file_uri) ? $file_uri : NULL;
      }
      if (isset($data['avatar'])) {
        $data['avatar'] = $this->fileUrlGenerator->generateAbsoluteString($data['avatar']);
      }
    }
    $this->moduleHandler->alter('disqus_user_data', $data);

    return $data;
  }

}
