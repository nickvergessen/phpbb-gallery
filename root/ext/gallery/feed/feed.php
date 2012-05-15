<?php
/**
*
* @package Gallery - Feed Extension
* @copyright (c) 2012 nickvergessen - http://www.flying-bits.org/
* @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
*
*/

/**
* @ignore
*/

if (!defined('IN_PHPBB'))
{
	exit;
}

class phpbb_ext_gallery_feed
{
	/**
	* Separator for title elements to separate items (for example album / image_name)
	*/
	private $separator = "\xE2\x80\xA2"; // &bull;

	/**
	* Separator for the statistics row (Uploaded by, time, comments, etc.)
	*/
	private $separator_stats = "\xE2\x80\x94"; // &mdash;

	private $feed_time = 0;
	private $sql_where = '';
	private $images_data = array();

	public function __construct($album_id)
	{
		if ($album_id)
		{
			$this->init_album_feed($album_id);
		}
		else
		{
			$this->init_gallery_feed();
		}
	}

	private function init_album_feed($album_id)
	{
		global $phpbb_ext_gallery;

		$album_data = phpbb_ext_gallery_core_album::get_info($album_id);
		$feed_enabled = (!empty($album_data['album_feed']) && (($album_data['album_user_id'] == 0) || $phpbb_ext_gallery->config->get('feed_enable_pegas')));

		if ($feed_enabled && $phpbb_ext_gallery->auth->acl_check('i_view', $album_id, $album_data['album_user_id']))
		{
			$this->sql_where = 'image_album_id = ' . (int) $album_id;
			$this->get_images($album_data);
		}
		else
		{
			trigger_error('NO_FEED');
		}
	}

	private function init_gallery_feed()
	{
		global $db, $phpbb_ext_gallery;

		$sql = 'SELECT album_id
			FROM ' . GALLERY_ALBUMS_TABLE . '
			WHERE album_feed = 1';
		$result = $db->sql_query($sql);
		$feed_albums = array();
		while ($row = $db->sql_fetchrow($result))
		{
			$feed_albums[] = (int) $row['album_id'];
		}
		$db->sql_freeresult($result);

		if (empty($feed_albums))
		{
			trigger_error('NO_FEED');
		}


		$moderator_albums = $phpbb_ext_gallery->auth->acl_album_ids('m_status', 'array', true, $phpbb_ext_gallery->config->get('feed_enable_pegas'));
		if (!empty($moderator_albums))
		{
			$moderator_albums = array_intersect($moderator_albums, $feed_albums);
		}
		$authorized_albums = array_diff($phpbb_ext_gallery->auth->acl_album_ids('i_view', 'array', true, $phpbb_ext_gallery->config->get('feed_enable_pegas')), $moderator_albums);
		if (!empty($authorized_albums))
		{
			$authorized_albums = array_intersect($authorized_albums, $feed_albums);
		}

		if (empty($moderator_albums) && empty($authorized_albums))
		{
			trigger_error('NO_FEED');
		}

		$this->sql_where = '(' . ((!empty($authorized_albums)) ? '(' . $db->sql_in_set('image_album_id', $authorized_albums) . ' AND image_status <> ' . phpbb_ext_gallery_core_image::STATUS_UNAPPROVED . ')' : '');
		$this->sql_where .= ((!empty($moderator_albums)) ? ((!empty($authorized_albums)) ? ' OR ' : '') . '(' . $db->sql_in_set('image_album_id', $moderator_albums, false, true) . ')' : '') . ')';

		$this->get_images();
	}

	public function get_images($album_data = false)
	{
		global $db, $phpbb_ext_gallery;

		$sql_array = array(
			'SELECT'		=> 'i.*',
			'FROM'			=> array(GALLERY_IMAGES_TABLE => 'i'),

			'WHERE'			=> $this->sql_where . ' AND i.image_status <> ' . phpbb_ext_gallery_core_image::STATUS_ORPHAN,
			'ORDER_BY'		=> 'i.image_time DESC',
		);

		if ($album_data == false)
		{
			$sql_array['SELECT'] .= ', a.album_name, a.album_status, a.album_id, a.album_user_id';
			$sql_array['LEFT_JOIN'] = array(
				array(
					'FROM'		=> array(GALLERY_ALBUMS_TABLE => 'a'),
					'ON'		=> 'i.image_album_id = a.album_id',
				),
			);
		}
		$sql = $db->sql_build_query('SELECT', $sql_array);
		$result = $db->sql_query_limit($sql, $phpbb_ext_gallery->config->get('feed_limit'));

		while ($row = $db->sql_fetchrow($result))
		{
			if ($this->feed_time == 0)
			{
				$this->feed_time = (int) $row['image_time'];
			}
			if ($album_data == false)
			{
				$this->images_data[$row['image_id']] = $row;
			}
			else
			{
				$this->images_data[$row['image_id']] = array_merge($row, $album_data);
			}
		}
		$db->sql_freeresult($result);
	}

	public function send_images()
	{
		global $user, $phpbb_ext_gallery, $template;

		foreach ($this->images_data as $image_id => $row)
		{
			$url_thumbnail = $phpbb_ext_gallery->url->append_sid('full', 'image', 'mode=thumbnail&amp;album_id=' . $row['image_album_id'] . '&amp;image_id=' . $image_id, true, '');
			$url_imagepage = $phpbb_ext_gallery->url->append_sid('full', 'image_page', 'album_id=' . $row['image_album_id'] . '&amp;image_id=' . $image_id, true, '');
			$url_fullsize = $phpbb_ext_gallery->url->append_sid('full', 'image', 'album_id=' . $row['image_album_id'] . '&amp;image_id=' . $image_id, true, '');
			$title = censor_text($row['album_name'] . ' ' . $this->separator . ' ' . $row['image_name']);

			$description = $row['image_desc'];
			if ($row['image_desc_uid'])
			{
				// make list items visible as such
				$description = str_replace('[*:' . $row['image_desc_uid'] . ']', '*&nbsp;', $description);
				// no BBCode
				strip_bbcode($description, $row['image_desc_uid']);
			}

			if ($row['image_contest'] == phpbb_ext_gallery_core_image::IN_CONTEST && !$phpbb_ext_gallery->auth->acl_check('m_status', $row['image_album_id'], phpbb_ext_gallery_core_album::PUBLIC_ALBUM))
			{
				$image_username = $user->lang['CONTEST_USERNAME'];
			}
			else if ($row['image_user_id'] == ANONYMOUS)
			{
				$image_username = $row['image_username'];
			}
			else
			{
				$url_profile = $phpbb_ext_gallery->url->append_sid('board', 'memberlist', 'mode=viewprofile&amp;u=' . $row['image_user_id'], true, '');
				$image_username = '<a href="' . $url_profile . '">' . $row['image_username'] . '</a>';
			}

			$template->assign_block_vars('item_row', array(
				'TITLE'			=> $title,
				'IMAGE_TIME'	=> self::format_date($row['image_time']),
				'DESCRIPTION'	=> $description,
				'STATISTIC'		=> $user->lang['STATISTICS'] . ': ' . $image_username . ' ' . $this->separator_stats . ' ' . $user->format_date($row['image_time']),
				'MIME_TYPE'		=> phpbb_ext_gallery_core_file::mimetype_by_filename($row['image_filename']),

				'U_VIEWIMAGE'	=> $url_imagepage,
				'U_FULL_IMAGE'	=> $url_fullsize,
				'U_THUMBNAIL'	=> $url_thumbnail,
			));
		}
	}

	public function send_header($title, $description, $self_link, $back_link)
	{
		global $template;

		header("Content-Type: application/atom+xml; charset=UTF-8");
		if ($this->feed_time)
		{
			header("Last-Modified: " . gmdate('D, d M Y H:i:s', $this->feed_time) . ' GMT');
		}

		$template->assign_vars(array(
			'TITLE'			=> $title,
			'DESCRIPTION'	=> $description,

			'U_SELF_LINK'		=> $self_link,
			'U_BACK_LINK'		=> $back_link,
		));
	}

	public function send_footer()
	{
		garbage_collection();
		exit_handler();
	}

	static public function format_date($time)
	{
		static $zone_offset;
		static $offset_string;

		if (empty($offset_string))
		{
			global $user;

			$zone_offset = (int) $user->timezone + (int) $user->dst;

			$sign = ($zone_offset < 0) ? '-' : '+';
			$time_offset = abs($zone_offset);

			$offset_seconds	= $time_offset % 3600;
			$offset_minutes	= $offset_seconds / 60;
			$offset_hours	= ($time_offset - $offset_seconds) / 3600;

			$offset_string	= sprintf(" %s%02d%02d", $sign, $offset_hours, $offset_minutes);
		}

		return gmdate("D, d M Y H:i:s", $time + $zone_offset) . $offset_string;
	}
}
