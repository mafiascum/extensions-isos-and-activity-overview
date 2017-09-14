<?php

use \Symfony\Component\HttpFoundation\Response;

namespace mafiascum\isos_and_activity_overview\controller;

class main
{
    /* @var \phpbb\config\config */
    protected $config;

    /* @var \phpbb\controller\helper */
    protected $helper;

    /* @var \phpbb\language\language */
    protected $language;

    /* @var \phpbb\template\template */
    protected $template;

    /* @var \phpbb\db\driver\driver */
	protected $db;

    /* @var \phpbb\user */
	protected $user;

    /**
     * Constructor
     *
     * @param \phpbb\config\config      $config
     * @param \phpbb\controller\helper  $helper
     * @param \phpbb\language\language  $language
     * @param \phpbb\template\template  $template
     * @param \phpbb\db\driver\driver   $db
     * @param \phpbb\user               $user
     */
    public function __construct(\phpbb\config\config $config, \phpbb\controller\helper $helper, \phpbb\language\language $language, \phpbb\template\template $template,	\phpbb\db\driver\driver_interface $db, \phpbb\user $user)
    {
        $this->config   = $config;
        $this->helper   = $helper;
        $this->language = $language;
        $this->template = $template;
        $this->db       = $db;
        $this->user     = $user;
    }

    public function assign_template_for_topic_post_count($topic_id)
    {
        $sql = 'SELECT count(*) count, u.username, min(p.post_time) first_post_time, max(p.post_time) last_post_time
                FROM ' . POSTS_TABLE . ' p
                JOIN ' . USERS_TABLE . ' u
                ON p.poster_id = u.user_id
                WHERE p.topic_id = ' . $topic_id . '
                GROUP BY p.poster_id, u.username
                ORDER BY max(p.post_time) DESC';

        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result))
        {
            $daysSince = (int) ((time() - $row['last_post_time']) / 60 / 60 / 24);
            $hoursSince = (int) ((time() - $row['first_post_time']) / 60 / 60) % 24;
            $idleTime = "$daysSince day" . ($daysSince==1?"":"s") . " $hoursSince hour" . ($hoursSince==1?"":"s");
            $this->template->assign_block_vars('POSTS_BY_USER', array(
                'COUNT' => $row['count'],
                'USERNAME' => $row['username'],
                'FIRST_POST_TIME' => $this->user->format_date($row['first_post_time']),
                'LAST_POST_TIME' => $this->user->format_date($row['last_post_time']),
                'IDLE_TIME' => $idleTime,
            ));
        }
        $this->db->sql_freeresult($result);
    }

    /**
     * Controller for activity overview
     *
     * @param string $topic_id
     * @throws \phpbb\exception\http_exception
     * @return \Symfony\Component\HttpFoundation\Response A Symfony Response object
     */
    public function handle($topic_id)
    {
        if (!$topic_id) {
            throw new \phpbb\exception\http_exception(400, 'NO_TOPIC', $topic_id);
        }

        $this->assign_template_for_topic_post_count($topic_id);

        return $this->helper->render('activity_overview.html', $name);
    }
}
