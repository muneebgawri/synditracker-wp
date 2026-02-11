<?php
/**
 * GitHub Updater Class.
 * Standardized for Synditracker projects.
 */
if (!class_exists('Synditracker_GitHub_Updater')) {
    class Synditracker_GitHub_Updater
    {
    private $slug;
    private $plugin_file;
    private $github_repo;
    private $version;
    private $plugin_name;

    /**
     * Constructor.
     */
    public function __construct($plugin_file, $github_repo, $version, $plugin_name = 'Synditracker')
    {
        $this->plugin_file = $plugin_file;
        $this->slug        = dirname(plugin_basename($plugin_file));
        $this->github_repo = $github_repo;
        $this->version     = $version;
        $this->plugin_name = $plugin_name;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_popup_info'), 10, 3);
    }

    /**
     * Check GitHub for updates.
     */
    public function check_for_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote_data = $this->get_remote_data();
        if (!$remote_data) {
            return $transient;
        }

        if (version_compare($this->version, $remote_data->tag_name, '<')) {
            $obj              = new \stdClass();
            $obj->slug        = $this->slug;
            $obj->new_version = $remote_data->tag_name;
            $obj->url         = "https://github.com/{$this->github_repo}";
            $obj->package     = $remote_data->zipball_url;
            $obj->plugin      = plugin_basename($this->plugin_file);

            $transient->response[plugin_basename($this->plugin_file)] = $obj;
        }

        return $transient;
    }

    /**
     * Plugin info popup.
     */
    public function plugin_popup_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || $args->slug !== $this->slug) {
            return $result;
        }

        $remote_data = $this->get_remote_data();
        if (!$remote_data) {
            return $result;
        }

        $res = new \stdClass();
        $res->name           = $this->plugin_name;
        $res->slug           = $this->slug;
        $res->version        = $remote_data->tag_name;
        $res->author         = '<a href="https://muneebgawri.com">Muneeb Gawri</a>';
        $res->homepage       = "https://github.com/{$this->github_repo}";
        $res->download_link  = $remote_data->zipball_url;
        $res->sections       = array(
            'description' => sprintf('A professional-grade component of the %s system.', $this->plugin_name),
            'changelog'   => 'Check the GitHub repository for detailed changelogs.',
        );

        return $res;
    }

    /**
     * Fetch data from GitHub API.
     */
    private function get_remote_data()
    {
        $url      = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array('Accept' => 'application/vnd.github.v3+json'),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response));
        if (empty($data) || !isset($data->tag_name)) {
            return false;
        }

        return $data;
    }
    }
}
