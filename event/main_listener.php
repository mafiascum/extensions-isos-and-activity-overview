<?php
/**
 *
 * @package phpBB Extension - Mafiascum ISOS and Activity Monitor
 * @copyright (c) 2013 phpBB Group
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace mafiascum\isos\event;

/**
 * @ignore
 */
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
/**
 * Event listener
 */
class main_listener implements EventSubscriberInterface
{
    
    /* @var \phpbb\controller\helper */
    protected $helper;

    /* @var \phpbb\template\template */
    protected $template;

    /* @var \phpbb\request\request */
    protected $request;

    /* @var \phpbb\db\driver\driver */
    protected $db;
    
    /* @var \phpbb\user */
    protected $user;

    static public function getSubscribedEvents()
    {
        return array(
            'core.search_modify_param_before' => 'process_multi_author_search',
            'core.search_modify_url_parameters' => 'propagate_multi_author_url_params',
            'core.user_setup'  => 'load_language_on_setup',
            'core.viewtopic_assign_template_vars_before' => 'inject_template_vars',
            'core.viewtopic_modify_page_title' => 'viewtopic_modify_page_title',
            'core.viewtopic_modify_post_row' => 'viewtopic_modify_post_row',
            'core.submit_post_end' => 'submit_post_end',
			'core.ucp_profile_modify_signature' => 'ucp_profile_modify_signature',
			'core.ucp_profile_modify_signature_sql_ary' => 'ucp_profile_modify_signature_sql_ary',
			'core.acp_board_config_edit_add' => 'acp_board_config_edit_add',
        );
    }

    /**
     * Constructor
     *
     * @param \phpbb\controller\helper    $helper        Controller helper object
     * @param \phpbb\template\template    $template    Template object
     * @param \phpbb\request\request    $request    Request object
     * @param \phpbb\db\driver\driver_interface    $db    DB object
     * @param \phpbb\user    $user    User object
     */
    public function __construct(\phpbb\controller\helper $helper, \phpbb\template\template $template, \phpbb\request\request $request, \phpbb\db\driver\driver_interface $db, \phpbb\user $user)
    {
        $this->helper = $helper;
        $this->template = $template;
        $this->request = $request;
        $this->db = $db;
        $this->user = $user;
    }

    public function inject_users_for_topic($topic_id)
    {
        global $phpbb_root_path, $phpEx;
        $sql = 'SELECT DISTINCT p.poster_id, u.username
                FROM ' . POSTS_TABLE . ' p
                JOIN ' . USERS_TABLE . ' u
                ON p.poster_id = u.user_id
                WHERE p.topic_id = ' . $topic_id . '
                ORDER BY lower(u.username)';

        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $this->template->assign_block_vars('TOPIC_USERS', array(
                'ID'       => $row['poster_id'],
                'USERNAME' => $row['username'],
            ));
        }
        $this->db->sql_freeresult($result);
        
        $this->template->assign_vars(array(
            'U_ISO_BASE_URL'    => append_sid("{$phpbb_root_path}search.{$phpEx}"),
        ));
    }
    
    public function viewtopic_modify_page_title($event) {

        $topic_id = $event['topic_data']['topic_id'];
        $forum_id = $event['forum_id'];
        $start = $event['start'];
        $seo = $this->create_viewtopic_seo($topic_id, $forum_id, $start);

        $this->template->assign_vars(array(
            'U_CANONICAL' => $seo['canonical'],
            'ROBOTS' => $seo['robots'],
        ));
    }

    public function inject_template_vars($event)
    {
        $topic_id = $event['topic_id'];

        $this->template->assign_vars(array(
            'U_ACTIVITY_OVERVIEW' => $this->helper->route('activity_overview_route', array('topic_id' => $topic_id))
        ));
        
        $this->inject_users_for_topic($topic_id);
    }

    function create_viewtopic_seo($topic_id, $forum_id, $start)
    {
        global $config, $phpEx, $phpbb_root_path;

        if($start % $config['posts_per_page'] != 0)
            return $this->create_viewtopic_default_seo();
        if(request_var('activity_overview', '') || request_var('vote_id', '') || request_var('st', '') || request_var('sk', '') || request_var('sd', '') || request_var('ppp', '') || request_var('p', '') || !empty(request_var('user_select', array('' => 0))))
            return $this->create_viewtopic_default_seo();
        
        return array(
            'canonical'    => $config['server_protocol'] . $config['server_name'] . $config['script_path'] . "/viewtopic.$phpEx?" . (($forum_id) ? "f=$forum_id&" : "") . "t=$topic_id" . ($start == 0 ? "" : "&start=$start"),
            'robots'    => 'INDEX, FOLLOW'
        );
    }

    function create_viewtopic_default_seo() {
        return array(
            'canonical'    => '',
            'robots'    => 'NOINDEX, FOLLOW'
        );
    }

    /**
     * Load the language file
     *     mafiascum/isos/language/en/demo.php
     *
     * @param \phpbb\event\data $event The event object
     */
    public function load_language_on_setup($event)
    {
        $lang_set_ext = $event['lang_set_ext'];
        $lang_set_ext[] = array(
            'ext_name' => 'mafiascum/isos',
            'lang_set' => 'common',
        );
        $event['lang_set_ext'] = $lang_set_ext;
    }

    public function process_multi_author_search($event)
    {
        $author_ids = $this->request->variable('author_ids', array(0));
        if (!empty($author_ids)) {
            $event['sort_by_sql'] = array('t' => 'p.post_time ASC, 1');
            $event['author_id_ary'] = $author_ids;
        }       
    }

    public function propagate_multi_author_url_params($event)
    {
        $author_ids = $this->request->variable('author_ids', array(0));
        if (!empty($author_ids)) {
            foreach ($author_ids as $author_id) {
                $event['u_search'] .= '&amp;author_ids[]=' . $author_id;
            }
        }
    }

    public function viewtopic_modify_post_row($event) {

        global $phpbb_root_path, $phpEx;
        $topic_id = $event['topic_data']['topic_id'];
        $poster_id = $event['poster_id'];
        $post_row = $event['post_row'];

        $post_row['ISO_URL'] = append_sid("{$phpbb_root_path}search.{$phpEx}", "author_id=-1&t={$topic_id}&author_ids%5B%5D={$poster_id}");

        $event['post_row'] = $post_row;
    }

    function submit_post_end($event) {

        if($event['mode'] == 'edit') {
            global $phpbb_log;
            $subject = $event['subject'];
            $data_ary = $event['data'];
            $username = $event['username'];
            $user = $this->user;
            $poster_id = $data_ary['poster_id'];
            
            if ($user->data['user_id'] == $poster_id) {
                $log_subject = ($subject) ? $subject : $data_ary['topic_title'];
                $phpbb_log->add('mod', $user->data['user_id'], $user->ip, 'LOG_POST_EDITED', false, array(
                    'forum_id' => $data_ary['forum_id'],
                    'topic_id' => $data_ary['topic_id'],
                    'post_id'  => $data_ary['post_id'],
                    $log_subject,
                    (!empty($username)) ? $username : $user->lang['GUEST'],
                    $data_ary['post_edit_reason']
                ));
            }
        }
	}

	function ucp_profile_modify_signature_sql_ary($event) {
		global $phpbb_container, $config;
		$utils = $phpbb_container->get('text_formatter.utils');
		$parser = $phpbb_container->get('text_formatter.parser');
		$sql_ary = $event['sql_ary'];

//		$min_lines_to_hide = 4;
		$disabled_tags = explode("|", $config['disabled_sig_bbcodes']);
		$text = $utils->unparse($sql_ary['user_sig']);

		foreach($disabled_tags as $disabled_tag) {
			$parser->disable_bbcode($disabled_tag);
		}

//		$signature_line_length = $this->calculate_signature_line_length($text);

		$xml = $parser->parse($text);

		$sql_ary['user_sig'] = $xml;
		$event['sql_ary'] = $sql_ary;
	}

	function calculate_signature_line_length($text) {
		return substr_count($text, "\n") + 1;
	}

	function acp_board_config_edit_add($event) {
		$mode = $event['mode'];

		switch($mode) {
			case 'signature':
				$display_vars = $event['display_vars'];
				$vars = $display_vars['vars'];
				$keys = array_keys($vars);
		
				$disabled_sig_bbcodes = array(
					'lang' => 'DISABLED_SIG_BBCODES',
					'validate' => 'string',
					'type' => 'text:40:150',
					'explain' => true
				);
				
				$arr_length = count($vars);
		
				$vars = array_merge(
					array_slice($vars, 0, $arr_length - 1),
					array('disabled_sig_bbcodes' => $disabled_sig_bbcodes),
					array_slice($vars, $arr_length - 1)
				);
		
				$display_vars['vars'] = $vars;
				$event['display_vars'] = $display_vars;
				break;
		}
	}
}