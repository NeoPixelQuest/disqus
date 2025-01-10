<?php

namespace Drupal\Tests\disqus\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests that Disqus configuration UI functionality.
 *
 * @group disqus
 */
class DisqusAdminTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'node',
    'file',
    'field',
    'field_ui',
    'disqus',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Verify the visitor-facility functionality works.
   */
  public function testSiteAfterInstall() {
    // Test if the home page is working after enabling Disqus.
    $this->drupalGet('<front>');
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Tests that the disqus configuration page works.
   */
  public function testDisqusConfigurationPage() {
    // Create and log in a user with administer disqus permission.
    $admin_user = $this->drupalCreateUser([
      'administer disqus',
    ]);
    $this->drupalLogin($admin_user);

    // Test if the configuration page is working.
    $this->drupalGet('/admin/config/services/disqus');
    $this->assertSession()->statusCodeEquals(200);

    // Confirm the expected fields are present.
    $this->assertSession()->fieldExists('disqus_domain');
    $this->assertSession()->fieldExists('disqus_localization');
    $this->assertSession()->fieldExists('disqus_inherit_login');
    $this->assertSession()->fieldExists('disqus_track_newcomment_ga');
    $this->assertSession()->fieldExists('disqus_notify_newcomment');
    $this->assertSession()->fieldExists('disqus_useraccesstoken');
    $this->assertSession()->fieldExists('disqus_publickey');
    $this->assertSession()->fieldExists('disqus_secretkey');
    $this->assertSession()->fieldExists('disqus_sso');
    $this->assertSession()->fieldExists('disqus_use_site_logo');
  }

  /**
   * Tests that user is able to add disqus field.
   */
  public function testDisqusFieldConfiguration() {
    $admin_user = $this->createUser([], 'Admin User', TRUE);
    $admin_user->addRole($this->createAdminRole());
    $this->drupalLogin($admin_user);
    $this->createContentType(['type' => 'test_type', 'name' => 'Test Type']);
    $this->drupalGet('admin/structure/types/manage/test_type/fields/add-field');
    $this->assertSession()->statusCodeEquals(200);
    // At the moment the rest of this test is only compatible with Drupal 10.1 and below.
    if (version_compare(\Drupal::VERSION, '10.2', '<')) {
      $this->getSession()->getDriver()->selectOption('//select[@id="edit-new-storage-type"]', 'disqus_comment');
      $this->getSession()->getPage()->fillField('edit-label', 'Disqus');
      $this->getSession()->getPage()->fillField('edit-field-name', 'disqus');
      $this->click('#edit-submit');
      $this->assertSession()->addressMatches('/^\/admin\/structure\/types\/manage\/test_type\/fields\/node.test_type.field_disqus\/storage$/');
      $this->assertSession()->statusCodeEquals(200);
      $this->click('#edit-submit');
      $this->assertSession()->addressMatches('/^\/admin\/structure\/types\/manage\/test_type\/fields\/node.test_type.field_disqus$/');
      $this->assertSession()->statusCodeEquals(200);

      // Enabling disqus comments on all nodes for now.
      $this->getSession()->getPage()->checkField('edit-default-value-input-field-disqus-0-status');
      $this->getSession()->getPage()->fillField('edit-default-value-input-field-disqus-0-identifier', 'test_identifier');
      $this->click('#edit-submit');
      $this->assertSession()->addressMatches('/^\/admin\/structure\/types\/manage\/test_type\/fields$/');
      $this->assertSession()->statusCodeEquals(200);
      $this->assertSession()->elementExists('xpath', '//table[@id="field-overview"]//td[text()="Disqus"]');
    }
  }

}
