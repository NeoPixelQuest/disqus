<?php

namespace Drupal\disqus\Plugin\migrate\destination;

use Drupal\migrate\Plugin\migrate\destination\DestinationBase;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Row;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Disqus comment destination.
 *
 * @MigrateDestination(
 *   id = "disqus_destination"
 * )
 */
class DisqusComment extends DestinationBase implements ContainerFactoryPluginInterface {
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
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, MigrationInterface $migration, LoggerInterface $logger, ConfigFactoryInterface $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration);
    $this->logger = $logger;
    $this->config = $config_factory->get('disqus.settings');
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
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function fields(MigrationInterface $migration = NULL) {
    return [
      'disqus_id' => $this->t('The disqus ID'),
      'message' => $this->t('The comment body.'),
      'parent' => $this->t('Parent comment ID. If set to null, this comment is not a reply to an existing comment.'),
      'identifier' => $this->t('The disqus identifier to look up the correct thread.'),
      'title' => $this->t("The title of the comment's thread page."),
      'author_email' => $this->t("The comments author's email."),
      'author_name' => $this->t("The comments author's name."),
      'author_url' => $this->t("The comments author's url."),
      'date' => $this->t('The time that the comment was posted as a Unix timestamp.'),
      'ip_address' => $this->t("The IP address that the comment was posted from."),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids['disqus_id']['type'] = 'string';
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  public function import(Row $row, array $old_destination_id_values = []) {
    $identifier = $row->getDestinationProperty('identifier');
    $disqus = disqus_api();
    if ($disqus) {
      try {
        $thread = $disqus->threads->details(
          [
            'forum' => $this->config->get('disqus_domain'),
            'thread:ident' => $identifier,
            'thread' => '1',
          ]
        );
      }
      catch (\Exception $exception) {
        $this->logger->error('Error loading thread details for entity : @identifier. Check your API keys.', ['@identifier' => $identifier]);
        $thread = NULL;
      }
      if (!isset($thread->id)) {
        try {
          $thread = $disqus->threads->create(
            [
              'forum' => $this->config->get('disqus_domain'),
              'access_token' => $this->config->get('advanced.disqus_useraccesstoken'),
              'title' => $row->getDestinationProperty('title'),
              'identifier' => $identifier,
            ]
          );
        }
        catch (\Exception $exception) {
          $this->logger->error('Error creating thread for entity : @identifier. Check your user access token.', ['@identifier' => $identifier]);
        }
      }
      try {
        $message = $row->getDestinationProperty('message');
        $author_name = $row->getDestinationProperty('author_name');
        $author_email = $row->getDestinationProperty('author_email');
        $author_url = $row->getDestinationProperty('author_url');
        $date = $row->getDestinationProperty('date');
        $ip_address = $row->getDestinationProperty('ip_address');
        $ids = FALSE;
        if (empty($author_name) || empty($author_email)) {
          // Post comment as created by site's moderator.
          $ids = [
            'disqus_id' => $disqus->posts->create([
              'message' => $message,
              'thread' => $thread->id,
              'access_token' => $this->config->get('advanced.disqus_useraccesstoken'),
              'date' => $date,
              'ip_address' => $ip_address,
            ])->id,
          ];
        }
        else {
          // Cannot create comment as anonymous user, needs 'api_key'
          // (api_key is not the public key).
          $ids = [
            'disqus_id' => $disqus->posts->create([
              'thread' => $thread->id,
              'message' => $message,
              'author_name' => $author_name,
              'author_email' => $author_email,
              'author_url' => $author_url,
              'api_key' => $this->config->get('advanced.disqus_publickey'),
            ]),
          ];
        }
        return $ids;
      }
      catch (\Exception $exception) {
        $this->logger->error('Error creating post on thread @thread, error: @error', [
          '@thread' => $thread->id,
          '@error' => $exception->getMessage(),
        ]);
      }
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    $disqus = disqus_api();
    if ($disqus) {
      try {
        $post = $disqus->posts->details([
          'post' => $destination_identifier['disqus_id'],
        ]);
      }
      catch (\Exception $exception) {
        $this->logger->error('Error loading thread details for entity : @identifier. Check your API keys.', ['@identifier' => $destination_identifier['disqus_id']]);
        $post = NULL;
      }
      if (!isset($post->id)) {
        $this->logger->notice(
            'Unable to find post: @identifier when trying to delete it. Maybe it was already deleted?',
            ['@identifier' => $destination_identifier['disqus_id']]
          );
        return;
      }
      try {
        $disqus->posts->remove([
          'access_token' => $this->config->get('advanced.disqus_useraccesstoken'),
          'post' => $destination_identifier['disqus_id'],
        ]
        );
      }
      catch (\Exception $exception) {
        $this->logger->error('Error deleting post @identifier.',
          ['@identifier' => $destination_identifier['disqus_id']]);
      }
      return FALSE;
    }
  }

}
