<?php

namespace Drupal\disqus\Plugin\migrate\source;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\migrate\Plugin\migrate\source\SourcePluginBase;
use Drupal\migrate\Row;
use Drupal\migrate\Plugin\MigrationInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Disqus comment source using disqus-api.
 *
 * @MigrateSource(
 *   id = "disqus_source",
 *   source_module = "disqus"
 * )
 */
class DisqusComment extends SourcePluginBase implements ContainerFactoryPluginInterface {

  /**
   * Iterator.
   *
   * @var \ArrayIterator
   */
  protected $iterator;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The disqus.settings configuration.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs Disqus comments destination plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implemetation definition.
   * @param \Drupal\migrate\Plugin\MigrationInterface $migration
   *   The migration.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, LoggerInterface $logger, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->logger = $logger;
    $this->config = $config_factory->get('disqus.settings');
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('logger.factory')->get('disqus'),
      $container->get('config.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['id']['type'] = 'integer';
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    return [
      'id' => $this->t('Comment ID.'),
      'pid' => $this->t('Parent comment ID. If set to null, this comment is not a reply to an existing comment.'),
      'identifier' => $this->t("The disqus identifier to look up the corrent thread."),
      'name' => $this->t("The comment author's name."),
      'user_id' => $this->t('The disqus user-id of the author who commented.'),
      'email' => $this->t("The comment author's email address."),
      'url' => $this->t("The author's home page address."),
      'ipAddress' => $this->t("The author's IP address."),
      'isAnonymous' => $this->t('If true, this comments has been posted by an anonymous user.'),
      'isApproved' => $this->t('If the comment is approved or not.'),
      'createdAt' => $this->t('The time that the comment was created.'),
      'comment' => $this->t('The comment body.'),
      'isEdited' => $this->t('Boolean value indicating if the comment has been edited or not.'),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $row->setSourceProperty('uid', 0);
    $email = $row->getSourceProperty('email');
    $user = $this->entityTypeManager->getStorage('user')->getQuery()
      ->condition('mail', $email)
      ->accessCheck(FALSE)
      ->execute();
    if ($user) {
      $row->setSourceProperty('uid', key($user));
    }
    return parent::prepareRow($row);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return 'Disqus comments';
  }

  /**
   * {@inheritdoc}
   */
  public function initializeIterator() {
    $items = [];

    if ($disqus = disqus_api()) {
      $items = [];
      try {
        $posts = $disqus->forums->listPosts(['forum' => $this->config->get('disqus_domain')]);
      }
      catch (\Exception $exception) {
        $this->messenger()->addMessage($this
          ->t('There was an error loading the forum details. Please check you API keys and try again.'),
          MessengerInterface::TYPE_ERROR
        );
        $this->logger->error('Error loading the Disqus PHP API. Check your forum name.', []);
        return new \ArrayIterator($items);
      }

      foreach ($posts as $post) {
        $id = $post->id;
        $items[$id]['id'] = $id;
        $items[$id]['pid'] = $post->parent;
        $thread = $disqus->threads->details(['thread' => $post->thread]);
        $items[$id]['identifier'] = $thread->identifier ?? null;
        $items[$id]['name'] = $post->author->name;
        $items[$id]['email'] = $post->author->email ?? null;
        $items[$id]['user_id'] = $post->author->id ?? null;
        $items[$id]['url'] = $post->author->url;
        $items[$id]['ipAddress'] = $post->ipAddress ?? null;
        $items[$id]['isAnonymous'] = $post->author->isAnonymous;
        $items[$id]['createdAt'] = $post->createdAt;
        $items[$id]['comment'] = $post->message;
        $items[$id]['isEdited'] = $post->isEdited;
      }
    }

    return new \ArrayIterator($items);
  }

}
