<?php

namespace Drupal\disqus\Plugin\migrate;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\migrate\Exception\RequirementsException;
use Drupal\migrate\Plugin\MigrationDeriverTrait;
use Drupal\migrate\Row;
use Drupal\migrate_drupal\Plugin\migrate\source\DrupalSqlBase;

/**
 * Deriver for Disqus module.
 *
 * @see \Drupal\node\Plugin\migrate\D7NodeDeriver
 */
class DisqusDeriver extends DeriverBase {

  use MigrationDeriverTrait;

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $source = static::getSourcePlugin('disqus_enabled_content_types');
    assert($source instanceof DrupalSqlBase);

    try {
      $source->checkRequirements();
    }
    catch (RequirementsException $e) {
      // Nothing to generate.
      return $this->derivatives;
    }

    try {
      foreach ($source as $row) {
        assert($row instanceof Row);
        $node_type = $row->getSourceProperty('type');
        $derivative_definition = $base_plugin_definition;
        $derivative_definition['source']['node_type'] = $node_type;

        $discus_derived_migrations = [
          'd7_disqus_field',
        ];
        $required_dependencies = $derivative_definition['migration_dependencies']['required'] ?? [];
        foreach ($discus_derived_migrations as $discus_derived_migration_id) {
          $key = array_search($discus_derived_migration_id, $required_dependencies);
          if ($key == FALSE) {
            $derivative_definition['migration_dependencies']['required'][$key] .= ':' . $node_type;
          }
        }

        $this->derivatives[$node_type] = $derivative_definition;
      }
    }
    catch (DatabaseExceptionWrapper $e) {
      // Once we begin iterating the source plugin it is possible that the
      // source tables will not exist.
    }
    return $this->derivatives;
  }

}
