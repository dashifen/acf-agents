<?php

namespace Dashifen\ACFAgent;

use WP_Post;
use DirectoryIterator;
use Dashifen\WPHandler\Agents\AbstractAgent;
use Dashifen\Repository\RepositoryException;
use Dashifen\ACFAgent\Repositories\FieldGroup;
use Dashifen\WPHandler\Handlers\HandlerInterface;
use Dashifen\WPHandler\Handlers\HandlerException;

class FieldGroupAgent extends AbstractAgent
{
    /**
     * @var string
     */
    protected $acfFolder = '';
    
    /**
     * FieldGroupAgent constructor.
     *
     * @param HandlerInterface $handler
     * @param string                $folder
     */
    public function __construct (HandlerInterface $handler, string $folder)
    {
        parent::__construct($handler);
        $this->acfFolder = $folder;
    }
    
    /**
     * initialize
     *
     * Links protected members of this object to the WordPress ecosystem.
     *
     * @throws HandlerException
     */
    public function initialize (): void
    {
        if (!$this->isInitialized()) {
            $this->addAction('init', 'importFieldGroups');
            $this->addAction('save_post_acf-field-group', 'exportCustomFieldGroups', 1000);
        }
    }
    /**
     * importFieldGroups
     *
     * Creates or updates ACF field groups based on JSON files.
     *
     * @returns void
     * @throws RepositoryException
     * @throws HandlerException
     */
    protected function importFieldGroups (): void
    {
        if (self::isDebug() || !get_transient(__FUNCTION__)) {
            
            // if we leave our export action running, then after we import,
            // the field group is immediately exported again.  this creates a
            // world in which the DB version is always older than the json
            // files, and therefore, we'd always import.  instead, we just
            // remove the action here and then add it back in at the end of
            // the method.
            
            $this->removeAction('save_post_acf-field-group', 'exportCustomFieldGroups', 1000);
            
            $jsonDefinitions = $this->getFieldGroupDefinitions();
            $registeredFieldGroups = $this->getRegisteredFieldGroups();
            foreach ($jsonDefinitions as $fieldGroupTitle => $fieldGroup) {
                
                // if the title of a field group we identify from files on the
                // filesystem is not in the list of registered groups, we'll
                // definitely need to import it.  otherwise, only if the
                // version in the database is obsolete do we want to import.
                
                $unregistered = !array_key_exists($fieldGroupTitle, $registeredFieldGroups);
                $obsolete = !$unregistered && $registeredFieldGroups[$fieldGroupTitle] < $fieldGroup->lastModified;
                
                if ($unregistered || $obsolete) {
                    acf_import_field_group($fieldGroup->content);
                }
            }
            
            // HOUR_IN_SECONDS * 12 means we'll check roughly twice a day
            // but the isDebug() test in our if's conditional means it'll
            // happen all the time on the development server.  once that's set
            // we re-add our export action.
            
            set_transient(__FUNCTION__, time(), HOUR_IN_SECONDS * 12);
            $this->addAction('save_post_acf-field-group', 'exportCustomFieldGroups', 1000);
        }
    }
    
    /**
     * getRegisteredFieldGroups
     *
     * Returns a map of registered field group names to their WP_Post object.
     *
     * @return WP_Post[]
     */
    private function getRegisteredFieldGroups (): array
    {
        $postData = [
            'post_type'   => 'acf-field-group',
            'post_status' => [
                'acf-disabled',
                'publish',
            ]
        ];
        
        // using the post data above, we get a list of all of the ACF field
        // group posts.  then, looping over them, we make a map of their titles
        // to their last modified timestamps.  this is all that the calling
        // scope needs to do its work.
        
        $timezone = date_default_timezone_get();
        date_default_timezone_set('America/New_York');
        foreach (get_posts($postData) as $group) {
            $groups[$group->post_title] = strtotime($group->post_modified);
        }
        
        date_default_timezone_set($timezone);
        return $groups ?? [];
    }
    
    /**
     * getFieldGroupDefinitions
     *
     * Returns an array of FieldGroup objects which contain information about
     * the groups that exist as exported JSON on the filesystem.
     *
     * @return FieldGroup[]
     * @throws RepositoryException
     */
    private function getFieldGroupDefinitions (): array
    {
        $timezone = date_default_timezone_get();
        date_default_timezone_set($this->getDefaultTimezone());
        $files = new DirectoryIterator($this->acfFolder);
        
        foreach ($files as $file) {
            if ($file->getExtension() === 'json') {
                $file = $file->getPathname();
                $data = file_get_contents($file);
                $json = json_decode($data, true);
                
                $fieldGroups[$json['title']] = new FieldGroup([
                    'lastModified' => filemtime($file),
                    'content'      => $json,
                ]);
            }
        }
        
        date_default_timezone_set($timezone);
        return $fieldGroups ?? [];
    }
    
    /**
     * getDefaultTimezone
     *
     * Returns 'America/New_York' by default.  Override this method to switch
     * to a different timezone as desired.
     *
     * @return string
     */
    private function getDefaultTimezone (): string
    {
        return 'America/New_York';
    }
    
    /**
     * exportCustomFieldGroups
     *
     * Automatically updates a JSON file with information about a given
     * ACF field group every time it's saved.
     *
     * @param int $postId
     */
    protected function exportCustomFieldGroups (int $postId): void
    {
        [$acfName, $filename] = $this->getFieldGroupDetails($postId);
        if (!empty($acfName)) {
            
            // armed with the name of an ACF group, we can get it's contents,
            // i.e. the fields in the group, as well.  then, as long as it has
            // fields, we'll write it all to a file that can be committed to
            // git and imported in other places where this code is used
            
            $contents = $this->getFieldGroupContents($acfName);
            
            if (!empty($contents)) {
                $filename = sprintf('%s/%s.json', $this->acfFolder, $filename);
                file_put_contents($filename, $contents);
            }
        }
    }
    
    /**
     * getFieldGroupDetails
     *
     * Gets the name and excerpt for the specified post and returns them.
     *
     * @param int $postId
     *
     * @return array
     */
    private function getFieldGroupDetails (int $postId): array
    {
        global $wpdb;
        
        // here we want to get some post data about the specified ID.  we
        // could instantiate a WP_Post object, but that has a lot of overhead
        // when all we want are the value of two specific columns in the
        // database.  instead, we'll just prepare and execute a fairly
        // straightforward query for what we need and return the results of
        // it to the calling scope.  note:  this function is executed based
        // on an ACF specific hook, so we know that $postId will be an acf
        // field group; we don't need to worry about that in our query here.
        
        $statement = $wpdb->prepare(
        /** @lang text */
            
            "SELECT post_name, post_excerpt FROM $wpdb->posts WHERE ID=%d",
            $postId
        );
        
        return $wpdb->get_row($statement, ARRAY_N);
    }
    
    /**
     * getFieldGroupContents
     *
     * Given the name of an ACF field group, uses ACF functions to extract the
     * JSON description of it and returns it.
     *
     * @param string $acfName
     *
     * @return string
     */
    private function getFieldGroupContents (string $acfName): string
    {
        $fieldGroup = acf_get_field_group($acfName);
        
        if (!empty($fieldGroup)) {
            $fieldGroup['fields'] = acf_get_fields($fieldGroup);
            $json = acf_prepare_field_group_for_export($fieldGroup);
        }
        
        return json_encode($json ?? '', JSON_PRETTY_PRINT);
    }
}
