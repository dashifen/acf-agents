<?php

namespace Dashifen\ACFAgent\Repositories;

use Dashifen\Repository\Repository;

/**
 * Class FieldGroup
 *
 * @property-read array $content
 * @property-read int   $lastModified
 *
 * @package Dashifen\ACFAgent\Repositories
 */
class FieldGroup extends Repository
{
  /**
   * @var array
   */
  protected $content;
  
  /**
   * @var int
   */
  protected $lastModified = 0;
  
  /**
   * setContent
   *
   * Sets the content property, and if the title is empty, sets that one,
   * too.
   *
   * @param array $content
   *
   * @return void
   */
  protected function setContent(array $content): void
  {
    $this->content = $content;
  }
  
  /**
   * setLastModified
   *
   * Sets the last modified property.
   *
   * @param int $lastModified
   *
   * @return void
   */
  protected function setLastModified(int $lastModified): void
  {
    $this->lastModified = $lastModified;
  }
}
