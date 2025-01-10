<?php

namespace Drupal\disqus\EventSubscriber;

use Drupal\disqus\Event\NewCommentEvent;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\user\EntityOwnerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Matcher\UrlMatcherInterface;

/**
 * Emails notifications of new comment.
 */
class NewCommentSubscriber implements EventSubscriberInterface {

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * A logger instance.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * A router implementation which does not check access.
   *
   * @var \Symfony\Component\Routing\Matcher\UrlMatcherInterface
   */
  protected $accessUnawareRouter;

  /**
   * Constructs a NewCommentSubscriber object.
   *
   * @param \Drupal\Core\Mail\MailManagerInterface $mail_manager
   *   The email plugin manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   A logger factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Symfony\Component\Routing\Matcher\UrlMatcherInterface $access_unaware_router
   *   A router implementation which does not check access.
   */
  public function __construct(MailManagerInterface $mail_manager, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, LanguageManagerInterface $language_manager, UrlMatcherInterface $access_unaware_router) {
    $this->mailManager = $mail_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('mail');
    $this->languageManager = $language_manager;
    $this->accessUnawareRouter = $access_unaware_router;
  }

  /**
   * Send email notification of new comment.
   *
   * @param \Drupal\disqus\Event\NewCommentEvent $event
   *   The new comment event object.
   */
  public function onNewComment(NewCommentEvent $event) {
    $post = $event->getPost();

    // Try and match a thread ID to an entity first.
    $entity = NULL;
    $identifiers = $post->thread->identifiers;
    foreach ($identifiers as $identifier) {
      $parts = explode('/', $identifier);
      if (count($parts) === 2) {
        list($entity_type_id, $entity_id) = $parts;
        try {
          if ($storage = $this->entityTypeManager->getStorage($entity_type_id)) {
            if ($entity = $storage->load($entity_id)) {
              break;
            }
          }
        }
        catch (\Exception $e) {
          // No problem; try the next identifier.
          continue;
        }
      }
    }

    // Fall back to matching the post URL itself to an entity.
    if (!$entity) {
      try {
        $result = $this->accessUnawareRouter->match($post->url);
        if (!empty($result['_route_object'])) {
          /** @var \Symfony\Component\Routing\Route $route */
          $route = $result['_route_object'];
          if ($parameters = $route->getOption('parameters')) {
            foreach ($parameters as $name => $options) {
              if (isset($options['type']) && strpos($options['type'], 'entity:') === 0 && !empty($result[$name])) {
                if ($result[$name] instanceof EntityOwnerInterface) {
                  $entity = $result[$name];
                  break;
                }
              }
            }
          }
        }
      }
      catch (\Exception $e) {
        // This is not business-critical logic; no need to let the exception
        // bubble further.
      }
    }

    if ($entity && $entity instanceof EntityOwnerInterface) {
      $owner = $entity->getOwner();
      if ($to = $owner->getEmail()) {
        $langcode = $owner->getPreferredLangcode();

        $message = $this->mailManager->mail('disqus', 'new_comment', $to, $langcode, [
          'post' => $post,
        ]);

        // Error logging is handled by \Drupal\Core\Mail\MailManager::mail().
        if ($message['result']) {
          $this->logger->notice('Sent email to %recipient', ['%recipient' => $to]);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    $events[NewCommentEvent::NEW_COMMENT][] = ['onNewComment'];
    return $events;
  }

}
