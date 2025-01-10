<?php

namespace Drupal\disqus\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\disqus\Event\NewCommentEvent;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\TempStore\SharedTempStoreFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for new comments.
 */
class NewCommentController extends ControllerBase {

  /**
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

  /**
   * The comments temp store.
   *
   * @var \Drupal\Core\TempStore\SharedTempStore
   */
  protected $tempStore;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * Event dispatcher service.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * NewComment constructor.
   *
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Core\TempStore\SharedTempStoreFactory $temp_store_factory
   *   The factory for the temp store object.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   Event dispatcher service.
   */
  public function __construct(FloodInterface $flood, SharedTempStoreFactory $temp_store_factory, TimeInterface $time, EventDispatcherInterface $event_dispatcher) {
    $this->flood = $flood;
    $this->tempStore = $temp_store_factory->get('disqus_new_comment');
    $this->time = $time;
    $this->eventDispatcher = $event_dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('flood'),
      $container->get('tempstore.shared'),
      $container->get('datetime.time'),
      $container->get('event_dispatcher')
    );
  }

  /**
   * Receives notification of a new comment.
   *
   * @param string $comment_id
   *   The comment ID.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   A Symfony response object.
   */
  public function receiver($comment_id) {
    // Do not process requests from the current user's IP if the limit for
    // invalid requests has been reached. Default is 5 invalid requests allowed
    // every 6 hours. This is arbitrarily based on the existing user login flood
    // settings.
    $flood_config = $this->config('user.flood');
    $limit = $flood_config->get('user_limit');
    $interval = $flood_config->get('user_window');

    if ($this->flood->isAllowed('disqus.new_comment', $limit, $interval)) {
      // Register a flood event; but it will be cleared if the request turns out
      // to be genuine.
      $this->flood->register('disqus.new_comment', $interval);
    }
    else {
      return new Response('', 400);
    }

    // Only process comments that have not been processed before.
    if ($this->tempStore->get($comment_id)) {
      // Comment has already been processed, return response as "Gone".
      return new Response('', 410);
    }
    /** @var \DisqusAPI $disqus */
    elseif ($disqus = disqus_api()) {
      $disqus_config = $this->config('disqus.settings');
      try {
        $post = $disqus->posts->details([
          'post' => $comment_id,
          'related' => 'thread',
        ]);

        if ($post && !empty($post->forum) && $post->forum === $disqus_config->get('disqus_domain')) {
          // Check that the post was created recently (in the last hour) before
          // sending any notification to avoid notifying of old posts. The
          // createdAt property is formatted like '2018-03-28T12:51:57'.
          $created = DrupalDateTime::createFromFormat('Y-m-d\TH:i:s', $post->createdAt, 'UTC')
            ->format('U');
          if ($this->time->getRequestTime() <= strtotime('+1 hour', $created)) {
            $this->tempStore->set($comment_id, TRUE);
            $this->eventDispatcher->dispatch(new NewCommentEvent($post), NewCommentEvent::NEW_COMMENT);

            // Clear flood control for this user as this was a genuine comment.
            $this->flood->clear('disqus.new_comment');

            // HTTP 204 is "No content", meaning "Processed succesfully, now
            // done".
            return new Response('', 204);
          }
          else {
            // Too late to notify of this comment, return response as "Gone".
            return new Response('', 410);
          }
        }
        else {
          // Comment not actually for this site.
          return new Response('', 404);
        }
      }
      catch (\DisqusAPIError $e) {
        // Pass along whatever the error code was from Disqus.
        return new Response('', $e->getCode());
      }
      catch (\Exception $exception) {
        // Any other error, consider the post not found.
        return new Response('', 404);
      }
    }
    else {
      // Service unavailable.
      return new Response('', 503);
    }
  }

}
