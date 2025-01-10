<?php

namespace Drupal\disqus\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Wraps a new comment event for event listeners.
 */
class NewCommentEvent extends Event {

  const NEW_COMMENT = 'disqus.comment.new';

  /**
   * Post object.
   *
   * @var object
   */
  protected $post;

  /**
   * Constructs a new comment event object.
   *
   * @param object $post
   *   The data sent through the post request.
   */
  public function __construct($post) {
    if (!is_object($post)) {
      throw new \InvalidArgumentException('Post must be an object');
    }

    $this->post = $post;
  }

  /**
   * Get the new post.
   *
   * @return object
   *   Returns the data sent via post request.
   */
  public function getPost() {
    return $this->post;
  }

}
