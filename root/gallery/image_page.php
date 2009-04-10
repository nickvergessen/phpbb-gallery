<?php
/**
*
* @package phpBB Gallery
* @version $Id$
* @copyright (c) 2007 nickvergessen nickvergessen@gmx.de http://www.flying-bits.org
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/

define('IN_PHPBB', true);
$phpbb_root_path = (defined('PHPBB_ROOT_PATH')) ? PHPBB_ROOT_PATH : '../';
$phpEx = substr(strrchr(__FILE__, '.'), 1);
include($phpbb_root_path . 'common.' . $phpEx);
$gallery_root_path = GALLERY_ROOT_PATH;
include($phpbb_root_path . 'includes/functions_display.' . $phpEx);
include($phpbb_root_path . 'includes/bbcode.' . $phpEx);

// Start session management
$user->session_begin();
$auth->acl($user->data);
$user->setup('mods/gallery');
$user->add_lang('mods/exif_data');

/**
* Filestructure:
*
* - Check the request and get image_data
* - Check the permissions and approval
* - Main work here...
* - Exif-Data
* - Rating
* - Posting comment
* - Listing comment
*
*/

include($phpbb_root_path . $gallery_root_path . 'includes/common.' . $phpEx);
include($phpbb_root_path . $gallery_root_path . 'includes/permissions.' . $phpEx);
include($phpbb_root_path . $gallery_root_path . 'includes/functions_display.' . $phpEx);

/**
* Check the request and get image_data
*/
$image_id = request_var('image_id', 0);
$image_data = get_image_info($image_id);

$album_id = $image_data['image_album_id'];
$album_data = get_album_info($album_id);

$user_id = $image_data['image_user_id'];

if (!file_exists($phpbb_root_path . GALLERY_UPLOAD_PATH . $image_data['image_filename']))
{
	$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . ' 
		SET image_filemissing = 1
		WHERE image_id = ' . $image_id;
	$db->sql_query($sql);
	trigger_error('IMAGE_NOT_EXIST');
}

/**
* Check the permissions and approval
*/
if (!gallery_acl_check('i_view', $album_id))
{
	if (!$user->data['is_registered'])
	{
		login_box("{$gallery_root_path}image_page.$phpEx", "album_id=$album_id&amp;image_id=$image_id", $user->lang['LOGIN_INFO']);
	}
	else
	{
		trigger_error('NOT_AUTHORISED');
	}
}
if (!gallery_acl_check('m_status', $album_id) && ($image_data['image_status'] == IMAGE_UNAPPROVED))
{
	trigger_error('NOT_AUTHORISED');
}

// Build the navigation
generate_album_nav($album_data);
// Salting the form...yumyum ...
add_form_key('gallery');

/**
* Main work here...
*/
$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . '
	SET image_view_count = image_view_count + 1
	WHERE image_id = ' . (int) $image_id;
$db->sql_query($sql);

$image_approval_sql = ' AND image_status <> ' . IMAGE_UNAPPROVED;
if (gallery_acl_check('m_status', $album_id))
{
	$image_approval_sql = '';
}

$sort_by_sql = array('t' => 'image_time', 'n' => 'image_name', 'u' => 'image_username', 'vc' => 'image_view_count', 'ra' => 'image_rate_avg', 'r' => 'image_rates', 'c' => 'image_comments', 'lc' => 'image_last_comment');
$sql_sort_by = (isset($sort_by_sql[$gallery_config['sort_method']])) ? $sort_by_sql[$gallery_config['sort_method']] : $sort_by_sql['t'];
if ($gallery_config['sort_order'] == 'd')
{
	$sql_next_condition = '<';
	$sql_next_ordering = 'DESC';
	$sql_previous_condition = '>';
	$sql_previous_ordering = 'ASC';
}
else
{
	$sql_next_condition = '>';
	$sql_next_ordering = 'ASC';
	$sql_previous_condition = '<';
	$sql_previous_ordering = 'DESC';
}
// Two sqls now, but much better performance!
// As we do not allow to duplicate images, we can relay on the id as second sort parameter
$sql = 'SELECT image_id, image_name
	FROM ' . GALLERY_IMAGES_TABLE . '
	WHERE image_album_id = ' . (int) $album_id . $image_approval_sql . "
		AND (($sql_sort_by = '" . $db->sql_escape($image_data[$sql_sort_by]) . "' AND image_id $sql_next_condition {$image_id})
		OR $sql_sort_by $sql_next_condition '" . $db->sql_escape($image_data[$sql_sort_by]) . "')
	ORDER BY $sql_sort_by $sql_next_ordering";
$result = $db->sql_query_limit($sql, 1);
$next_data = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

$sql = 'SELECT image_id, image_name
	FROM ' . GALLERY_IMAGES_TABLE . '
	WHERE image_album_id = ' . (int) $album_id . $image_approval_sql . "
		AND (($sql_sort_by = '" . $db->sql_escape($image_data[$sql_sort_by]) . "' AND image_id $sql_previous_condition {$image_id})
		OR $sql_sort_by $sql_previous_condition '" . $db->sql_escape($image_data[$sql_sort_by]) . "')
	ORDER BY $sql_sort_by $sql_previous_ordering";
$result = $db->sql_query_limit($sql, 1);
$previous_data = $db->sql_fetchrow($result);
$db->sql_freeresult($result);

$template->assign_vars(array(
	'U_VIEW_ALBUM'		=> append_sid("{$phpbb_root_path}{$gallery_root_path}album.$phpEx", "album_id=$album_id"),

	'UC_PREVIOUS_IMAGE'	=> generate_image_link('thumbnail', 'image_page', $previous_data['image_id'], $previous_data['image_name'], $album_id),
	'UC_PREVIOUS'		=> (!empty($previous_data)) ? generate_image_link('image_name_unbold', 'image_page_prev', $previous_data['image_id'], $previous_data['image_name'], $album_id) : '',
	'UC_IMAGE'			=> generate_image_link('medium', $gallery_config['link_imagepage'], $image_id, $image_data['image_name'], $album_id, ((substr($image_data['image_filename'], 0 -3) == 'gif') ? true : false)),
	'UC_NEXT_IMAGE'		=> generate_image_link('thumbnail', 'image_page', $next_data['image_id'], $next_data['image_name'], $album_id),
	'UC_NEXT'			=> (!empty($next_data)) ? generate_image_link('image_name_unbold', 'image_page_next', $next_data['image_id'], $next_data['image_name'], $album_id) : '',

	'EDIT_IMG'			=> $user->img('icon_post_edit', 'EDIT_IMAGE'),
	'DELETE_IMG'		=> $user->img('icon_post_delete', 'DELETE_IMAGE'),
	'REPORT_IMG'		=> $user->img('icon_post_report', 'REPORT_IMAGE'),
	'STATUS_IMG'		=> $user->img('icon_post_info', 'STATUS_IMAGE'),
	'U_DELETE'			=> ((gallery_acl_check('i_delete', $album_id) && ($image_data['image_user_id'] == $user->data['user_id']) && ($album_data['album_status'] != ITEM_LOCKED)) || gallery_acl_check('m_delete', $album_id)) ? append_sid("{$phpbb_root_path}{$gallery_root_path}posting.$phpEx", "mode=image&amp;submode=delete&amp;album_id=$album_id&amp;image_id=$image_id") : '',
	'U_EDIT'			=> ((gallery_acl_check('i_edit', $album_id) && ($image_data['image_user_id'] == $user->data['user_id']) && ($album_data['album_status'] != ITEM_LOCKED)) || gallery_acl_check('m_edit', $album_id)) ? append_sid("{$phpbb_root_path}{$gallery_root_path}posting.$phpEx", "mode=image&amp;submode=edit&amp;album_id=$album_id&amp;image_id=$image_id") : '',
	'U_REPORT'			=> (gallery_acl_check('i_report', $album_id) && ($image_data['image_user_id'] != $user->data['user_id'])) ? append_sid("{$phpbb_root_path}{$gallery_root_path}posting.$phpEx", "mode=image&amp;submode=report&amp;album_id=$album_id&amp;image_id=$image_id") : '',
	'U_STATUS'			=> (gallery_acl_check('m_status', $album_id)) ? append_sid("{$phpbb_root_path}{$gallery_root_path}mcp.$phpEx", "mode=queue_details&amp;album_id=$album_id&amp;option_id=$image_id") : '',

	'CONTEST_RANK'		=> ($image_data['image_contest_rank']) ? $user->lang['CONTEST_RESULT_' . $image_data['image_contest_rank']] : '',
	'IMAGE_NAME'		=> $image_data['image_name'],
	'IMAGE_DESC'		=> generate_text_for_display($image_data['image_desc'], $image_data['image_desc_uid'], $image_data['image_desc_bitfield'], 7),
	'IMAGE_BBCODE'		=> '[album]' . $image_id . '[/album]',
	'IMAGE_URL'			=> ($gallery_config['view_image_url']) ? generate_board_url(false) . '/' . $gallery_root_path . "image.$phpEx?album_id=$album_id&amp;image_id=$image_id" : '',
	'POSTER'			=> (gallery_acl_check('m_status', $album_id) || ($image_data['image_contest'] != IMAGE_CONTEST)) ? get_username_string('full', $image_data['image_user_id'], ($image_data['image_username']) ? $image_data['image_username'] : $user->lang['GUEST'], $image_data['image_user_colour']) : sprintf($user->lang['CONTEST_USERNAME_LONG'], $user->format_date(($album_data['contest_start'] + $album_data['contest_end']), false, true)),
	'IMAGE_TIME'		=> $user->format_date($image_data['image_time']),
	'IMAGE_VIEW'		=> $image_data['image_view_count'],

	'L_BOOKMARK_TOPIC'	=> ($image_data['favorite_id']) ? $user->lang['UNFAVORITE_IMAGE'] : $user->lang['FAVORITE_IMAGE'],
	'U_BOOKMARK_TOPIC'	=> ($user->data['user_id'] != ANONYMOUS) ? append_sid("{$phpbb_root_path}{$gallery_root_path}posting.$phpEx", "mode=image&amp;submode=" . (($image_data['favorite_id']) ?  'un' : '') . "favorite&amp;album_id=$album_id&amp;image_id=$image_id") : '',
	'L_WATCH_TOPIC'		=> ($image_data['watch_id']) ? $user->lang['UNWATCH_IMAGE'] : $user->lang['WATCH_IMAGE'],
	'U_WATCH_TOPIC'		=> ($user->data['user_id'] != ANONYMOUS) ? append_sid("{$phpbb_root_path}{$gallery_root_path}posting.$phpEx", "mode=image&amp;submode=" . (($image_data['watch_id']) ?  'un' : '') . "watch&amp;album_id=$album_id&amp;image_id=$image_id") : '',
	'S_WATCHING_TOPIC'	=> ($image_data['watch_id']) ? true : false,
	'S_ALBUM_ACTION'	=> append_sid("{$phpbb_root_path}{$gallery_root_path}image_page.$phpEx", "album_id=$album_id&amp;image_id=$image_id"),

	'U_RETURN_LINK'		=> append_sid("{$phpbb_root_path}{$gallery_root_path}album.$phpEx", "album_id=$album_id"),
	'S_RETURN_LINK'		=> $album_data['album_name'],
	'S_JUMPBOX_ACTION'	=> append_sid("{$phpbb_root_path}{$gallery_root_path}album.$phpEx"),
	'ALBUM_JUMPBOX'		=> gallery_albumbox(false, '', $album_id),
));

/**
* Exif-Data
*/
if ($gallery_config['exif_data'] && ($image_data['image_has_exif'] != EXIF_UNAVAILABLE) && (substr($image_data['image_filename'], -3, 3) == 'jpg') && function_exists('exif_read_data') && (gallery_acl_check('m_status', $album_id) || ($image_data['image_contest'] != IMAGE_CONTEST)))
{
	if ($image_data['image_has_exif'] == EXIF_DBSAVED)
	{
		$exif = unserialize($image_data['image_exif_data']);
	}
	else
	{
		$exif = @exif_read_data($phpbb_root_path . GALLERY_UPLOAD_PATH . $image_data['image_filename'], 0, true);
	}
	if (!empty($exif["EXIF"]))
	{
		$exif_data = array();

		if(isset($exif["EXIF"]["DateTimeOriginal"]))
		{
			$timestamp_year = substr($exif["EXIF"]["DateTimeOriginal"], 0, 4);
			$timestamp_month = substr($exif["EXIF"]["DateTimeOriginal"], 5, 2);
			$timestamp_day = substr($exif["EXIF"]["DateTimeOriginal"], 8, 2);
			$timestamp_hour = substr($exif["EXIF"]["DateTimeOriginal"], 11, 2);
			$timestamp_minute = substr($exif["EXIF"]["DateTimeOriginal"], 14, 2);
			$timestamp_second = substr($exif["EXIF"]["DateTimeOriginal"], 17, 2);
			$timestamp = (int) @mktime($timestamp_hour, $timestamp_minute, $timestamp_second, $timestamp_month, $timestamp_day, $timestamp_year);
			if ($timestamp)
			{
				$exif_data['exif_date'] = $user->format_date($timestamp + EXIFTIME_OFFSET);
			}
		}
		if(isset($exif["EXIF"]["FocalLength"]))
		{
			list($num, $den) = explode("/", $exif["EXIF"]["FocalLength"]);
			if ($den)
			{
				$exif_data['exif_focal'] = sprintf($user->lang['EXIF_FOCAL_EXP'], ($num / $den));
			}
		}
		if(isset($exif["EXIF"]["ExposureTime"]))
		{
			list($num, $den) = explode("/", $exif["EXIF"]["ExposureTime"]);
			if ($den)
			{
				if ($num > $den)
				{
					$exif_exposure = $num/$den;
				}
				else
				{
					$exif_exposure = ' 1/' . $den / $num ;
				}
				$exif_data['exif_exposure'] = sprintf($user->lang['EXIF_EXPOSURE_EXP'], $exif_exposure);
			}
		}
		if(isset($exif["EXIF"]["FNumber"]))
		{
			list($num,$den) = explode("/",$exif["EXIF"]["FNumber"]);
			if ($den)
			{
				$exif_data['exif_aperture'] = "F/" . ($num / $den);
			}
		}
		if(isset($exif["EXIF"]["ISOSpeedRatings"]) && !is_array($exif["EXIF"]["ISOSpeedRatings"]))
		{
			$exif_data['exif_iso'] = $exif["EXIF"]["ISOSpeedRatings"];
		}
		if (isset($exif["EXIF"]["WhiteBalance"]))
		{
			$exif_data['exif_whiteb'] = $user->lang['EXIF_WHITEB_' . (($exif["EXIF"]["WhiteBalance"]) ? 'MANU' : 'AUTO')];
		}
		if(isset($exif["EXIF"]["Flash"]))
		{
			if (isset($user->lang['EXIF_FLASH_CASE_' . $exif["EXIF"]["Flash"]]))
			{
				$exif_data['exif_flash'] = $user->lang['EXIF_FLASH_CASE_' . $exif["EXIF"]["Flash"]];
			}
		}
		if (isset($exif["IFD0"]["Model"]))
		{
			$exif_data['exif_cam_model'] = ucwords($exif["IFD0"]["Model"]);
		}
		if (isset($exif["EXIF"]["ExposureProgram"]))
		{
			if (isset($user->lang['EXIF_EXPOSURE_PROG_' . $exif["EXIF"]["ExposureProgram"]]))
			{
				$exif_data['exif_exposure_prog'] = $user->lang['EXIF_EXPOSURE_PROG_' . $exif["EXIF"]["ExposureProgram"]];
			}
		}
		if (isset($exif["EXIF"]["ExposureBiasValue"]))
		{
			list($num,$den) = explode("/", $exif["EXIF"]["ExposureBiasValue"]);
			if ($den)
			{
				if (($num / $den) == 0)
				{
					$exif_exposure_bias = 0;
				}
				else
				{
					$exif_exposure_bias = $exif["EXIF"]["ExposureBiasValue"];
				}
				$exif_data['exif_exposure_bias'] = sprintf($user->lang['EXIF_EXPOSURE_BIAS_EXP'], $exif_exposure_bias);
			}
		}
		if (isset($exif["EXIF"]["MeteringMode"]))
		{
			if (isset($user->lang['EXIF_METERING_MODE_' . $exif["EXIF"]["MeteringMode"]]))
			{
				$exif_data['exif_metering_mode'] = $user->lang['EXIF_METERING_MODE_' . $exif["EXIF"]["MeteringMode"]];
			}
		}

		if ($exif_data != '')
		{
			foreach ($exif_data as $exif => $value)
			{
				$template->assign_block_vars('exif_value', array(
					'EXIF_NAME'			=> $user->lang[strtoupper($exif)],
					'EXIF_VALUE'		=> htmlspecialchars($value),
				));
			}
			$template->assign_vars(array(
				'S_EXIF_DATA'	=> true,
			));
		}

		if ($image_data['image_has_exif'] == EXIF_UNKNOWN)
		{
			$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . '
				SET image_has_exif = ' . EXIF_AVAILABLE . '
				WHERE image_id = ' . $image_id;
			$db->sql_query($sql);
		}
	}
	else
	{
		$sql = 'UPDATE ' . GALLERY_IMAGES_TABLE . '
			SET image_has_exif = ' . EXIF_UNAVAILABLE . '
			WHERE image_id = ' . $image_id;
		$db->sql_query($sql);
	}
}

/**
* Rating
*/
if ($gallery_config['allow_rates'])
{
	$allowed_to_rate = $your_rating = $contest_rating_msg = $contest_result_hidden = false;

	if ($user->data['is_registered'])
	{
		$sql = 'SELECT *
			FROM ' . GALLERY_RATES_TABLE . '
			WHERE rate_image_id = ' . (int) $image_id . '
				AND rate_user_id = ' . (int) $user->data['user_id'];
		$result = $db->sql_query($sql);

		if ($db->sql_affectedrows($result) > 0)
		{
			$rated = $db->sql_fetchrow($result);
			$your_rating = $rated['rate_point'];
		}
		$db->sql_freeresult($result);
	}
	// Hide the result, while still rating on contests
	if ($image_data['image_contest'])
	{
		$contest_result_hidden = sprintf($user->lang['CONTEST_RESULT_HIDDEN'], $user->format_date(($album_data['contest_start'] + $album_data['contest_end']), false, true));
	}

	// Check: User didn't rate yet, has permissions, it's not the users own image and the user is logged in
	if (!$your_rating && gallery_acl_check('i_rate', $album_id) && ($user->data['user_id'] != $image_data['image_user_id']) && ($user->data['user_id'] != ANONYMOUS) && ($album_data['album_status'] != ITEM_LOCKED) && ($image_data['image_status'] != IMAGE_LOCKED))
	{
		$hide_rate = false;
		if ($album_data['contest_id'])
		{
			if (time() < ($album_data['contest_start'] + $album_data['contest_rating']))
			{
				$hide_rate = true;
				$contest_rating_msg = sprintf($user->lang['CONTEST_RATING_STARTS'], $user->format_date(($album_data['contest_start'] + $album_data['contest_rating']), false, true));
			}
			if (($album_data['contest_start'] + $album_data['contest_end']) < time())
			{
				$hide_rate = true;
				$contest_rating_msg = sprintf($user->lang['CONTEST_RATING_ENDED'], $user->format_date(($album_data['contest_start'] + $album_data['contest_end']), false, true));
			}
		}
		if (!$hide_rate)
		{
			for ($rate_scale = 1; $rate_scale <= $gallery_config['rate_scale']; $rate_scale++)
			{
				$template->assign_block_vars('rate_scale', array(
					'RATE_POINT'	=> $rate_scale,
				));
			}
		}
		$allowed_to_rate = true;
	}
	$template->assign_vars(array(
		'IMAGE_RATING'			=> ($image_data['image_rates'] <> 0) ? sprintf((($image_data['image_rates'] == 1) ? $user->lang['RATE_STRING'] : $user->lang['RATES_STRING']), $image_data['image_rate_avg'] / 100, $image_data['image_rates']) : $user->lang['NOT_RATED'],
		'S_YOUR_RATING'			=> $your_rating,
		'S_ALLOWED_TO_RATE'		=> $allowed_to_rate,
		'CONTEST_RATING'		=> $contest_rating_msg,
		'CONTEST_RESULT_HIDDEN'	=> $contest_result_hidden,
		'S_VIEW_RATE'			=> (gallery_acl_check('i_rate', $album_id)) ? true : false,
		'S_COMMENT_ACTION'		=> append_sid("{$phpbb_root_path}{$gallery_root_path}posting.$phpEx", "album_id=$album_id&amp;image_id=$image_id&amp;mode=comment&amp;submode=rate"),
	));
}

/**
* Posting comment
*/
if ($gallery_config['allow_comments'] && gallery_acl_check('c_post', $album_id) && ($album_data['album_status'] != ITEM_LOCKED) && (($image_data['image_status'] != IMAGE_LOCKED) || gallery_acl_check('m_status', $album_id)))
{
	$user->add_lang('posting');
	include("{$phpbb_root_path}includes/functions_posting.$phpEx");

	$bbcode_status	= ($config['allow_bbcode']) ? true : false;
	$smilies_status	= ($bbcode_status && $config['allow_smilies']) ? true : false;
	$img_status		= ($bbcode_status) ? true : false;
	$url_status		= ($config['allow_post_links']) ? true : false;
	$flash_status	= false;
	$quote_status	= true;

	// Build custom bbcodes array
	display_custom_bbcodes();

	// Build smilies array
	generate_smilies('inline', 0);

	$s_hide_comment_input = (time() < ($album_data['contest_start'] + $album_data['contest_end'])) ? true : false;

	$template->assign_vars(array(
		'S_ALLOWED_TO_COMMENT'	=> true,
		'S_HIDE_COMMENT_INPUT'	=> $s_hide_comment_input,
		'CONTEST_COMMENTS'		=> sprintf($user->lang['CONTEST_COMMENTS_STARTS'], $user->format_date(($album_data['contest_start'] + $album_data['contest_end']), false, true)),

		'BBCODE_STATUS'			=> ($bbcode_status) ? sprintf($user->lang['BBCODE_IS_ON'], '<a href="' . append_sid("{$phpbb_root_path}faq.$phpEx", 'mode=bbcode') . '">', '</a>') : sprintf($user->lang['BBCODE_IS_OFF'], '<a href="' . append_sid("{$phpbb_root_path}faq.$phpEx", 'mode=bbcode') . '">', '</a>'),
		'IMG_STATUS'			=> ($img_status) ? $user->lang['IMAGES_ARE_ON'] : $user->lang['IMAGES_ARE_OFF'],
		'FLASH_STATUS'			=> ($flash_status) ? $user->lang['FLASH_IS_ON'] : $user->lang['FLASH_IS_OFF'],
		'SMILIES_STATUS'		=> ($smilies_status) ? $user->lang['SMILIES_ARE_ON'] : $user->lang['SMILIES_ARE_OFF'],
		'URL_STATUS'			=> ($bbcode_status && $url_status) ? $user->lang['URL_IS_ON'] : $user->lang['URL_IS_OFF'],

		'S_BBCODE_ALLOWED'		=> $bbcode_status,
		'S_SMILIES_ALLOWED'		=> $smilies_status,
		'S_LINKS_ALLOWED'		=> $url_status,
		'S_BBCODE_IMG'			=> $img_status,
		'S_BBCODE_URL'			=> $url_status,
		'S_BBCODE_FLASH'		=> $flash_status,
		'S_BBCODE_QUOTE'		=> $quote_status,
		'L_COMMENT_LENGTH'		=> sprintf($user->lang['COMMENT_LENGTH'], $gallery_config['comment_length']),
	));

	// Different link, when we rate and dont comment
	if (!$s_hide_comment_input)
	{
		$template->assign_var('S_COMMENT_ACTION', append_sid("{$phpbb_root_path}{$gallery_root_path}posting.$phpEx", "album_id=$album_id&amp;image_id=$image_id&amp;mode=comment&amp;submode=add"));
	}
}


/**
* Listing comment
*/
if (($gallery_config['allow_comments'] && gallery_acl_check('c_read', $album_id)) && (time() > ($album_data['contest_start'] + $album_data['contest_end'])))
{
	$user->add_lang('viewtopic');
	$start = request_var('start', 0);
	$sort_order = (request_var('sort_order', 'ASC') == 'ASC') ? 'ASC' : 'DESC';
	$template->assign_vars(array(
		'S_ALLOWED_READ_COMMENTS'	=> true,
		'IMAGE_COMMENTS'			=> $image_data['image_comments'],
		'SORT_ASC'					=> ($sort_order == 'ASC') ? true : false,
	));

	if ($image_data['image_comments'] > 0)
	{
		$bbcode = new bbcode();

		$sql = 'SELECT c.*, u.*
			FROM ' . GALLERY_COMMENTS_TABLE . ' c
			LEFT JOIN ' . USERS_TABLE . " u
				ON c.comment_user_id = u.user_id
			WHERE c.comment_image_id = $image_id
			ORDER BY c.comment_id $sort_order";
		$result = $db->sql_query_limit($sql, $config['posts_per_page'], $start);

		while ($commentrow = $db->sql_fetchrow($result))
		{
			$edit_info = '';
			if ($commentrow['comment_edit_count'] > 0)
			{
				$sql_2 = 'SELECT c.comment_id, c.comment_edit_user_id, u.user_id, u.username, u.user_colour
					FROM ' . GALLERY_COMMENTS_TABLE . ' c
					LEFT JOIN ' . USERS_TABLE . ' u
						ON c.comment_edit_user_id = u.user_id
					WHERE c.comment_id = ' . (int) $commentrow['comment_id'];
				$result_2 = $db->sql_query($sql_2);
				$lastedit_row = $db->sql_fetchrow($result_2);
				$db->sql_freeresult($result_2);

				$edit_info = ($commentrow['comment_edit_count'] == 1) ? $user->lang['EDITED_TIME_TOTAL'] : $user->lang['EDITED_TIMES_TOTAL'];
				$edit_info = sprintf($edit_info, get_username_string('full', $lastedit_row['user_id'], $lastedit_row['username'], $lastedit_row['user_colour']), $user->format_date($commentrow['comment_edit_time'], false, true), $commentrow['comment_edit_count']);
			}

			// Maybe we'll cache this one day... maybe, one day!
			get_user_rank($commentrow['user_rank'], $commentrow['user_posts'], $commentrow['rank_title'], $commentrow['rank_image'], $commentrow['rank_image_src']);
			if ($commentrow['user_sig'] && $config['allow_sig'] && $user->optionget('viewsigs'))
			{
				$commentrow['user_sig'] = censor_text($commentrow['user_sig']);

				if ($commentrow['user_sig_bbcode_bitfield'])
				{
					$bbcode->bbcode_second_pass($commentrow['user_sig'], $commentrow['user_sig_bbcode_uid'], $commentrow['user_sig_bbcode_bitfield']);
				}

				$commentrow['user_sig'] = bbcode_nl2br($commentrow['user_sig']);
				$commentrow['user_sig'] = smiley_text($commentrow['user_sig']);
			}

			$template->assign_block_vars('commentrow', array(
				'U_COMMENT'		=> append_sid("{$phpbb_root_path}{$gallery_root_path}image_page.$phpEx", "album_id=$album_id&amp;image_id=$image_id&amp;start=$start&amp;sort_order=$sort_order") . '#' . $commentrow['comment_id'],
				'COMMENT_ID'	=> $commentrow['comment_id'],
				'TIME'			=> $user->format_date($commentrow['comment_time']),
				'TEXT'			=> generate_text_for_display($commentrow['comment'], $commentrow['comment_uid'], $commentrow['comment_bitfield'], 7),
				'EDIT_INFO'		=> $edit_info,
				'U_DELETE'		=> (gallery_acl_check('m_comments', $album_id) || (gallery_acl_check('c_delete', $album_id) && ($commentrow['comment_user_id'] == $user->data['user_id']) && $user->data['is_registered'])) ? append_sid("{$phpbb_root_path}{$gallery_root_path}posting.$phpEx", "album_id=$album_id&amp;image_id=$image_id&amp;mode=comment&amp;submode=delete&amp;comment_id=" . $commentrow['comment_id']) : '',
				'U_EDIT'		=> (gallery_acl_check('m_comments', $album_id) || (gallery_acl_check('c_edit', $album_id) && ($commentrow['comment_user_id'] == $user->data['user_id']) && $user->data['is_registered'])) ? append_sid("{$phpbb_root_path}{$gallery_root_path}posting.$phpEx", "album_id=$album_id&amp;image_id=$image_id&amp;mode=comment&amp;submode=edit&amp;comment_id=" . $commentrow['comment_id']) : '',
				'U_INFO'		=> ($auth->acl_get('a_')) ? append_sid("{$phpbb_root_path}{$gallery_root_path}mcp.$phpEx", 'mode=whois&amp;ip=' . $commentrow['comment_user_ip']) : '',

				'POSTER_AVATAR'			=> ($user->optionget('viewavatars')) ? get_user_avatar($commentrow['user_avatar'], $commentrow['user_avatar_type'], $commentrow['user_avatar_width'], $commentrow['user_avatar_height']) : '',
				'POST_AUTHOR_FULL'		=> get_username_string('full', $commentrow['user_id'], ($commentrow['user_id'] <> ANONYMOUS) ? $commentrow['username'] : ($user->lang['GUEST'] . ': ' . $commentrow['comment_username']), $commentrow['user_colour']),
				'POST_AUTHOR_COLOUR'	=> get_username_string('colour', $commentrow['user_id'], ($commentrow['user_id'] <> ANONYMOUS) ? $commentrow['username'] : ($user->lang['GUEST'] . ': ' . $commentrow['comment_username']), $commentrow['user_colour']),
				'POST_AUTHOR'			=> get_username_string('username', $commentrow['user_id'], ($commentrow['user_id'] <> ANONYMOUS) ? $commentrow['username'] : ($user->lang['GUEST'] . ': ' . $commentrow['comment_username']), $commentrow['user_colour']),
				'U_POST_AUTHOR'			=> get_username_string('profile', $commentrow['user_id'], ($commentrow['user_id'] <> ANONYMOUS) ? $commentrow['username'] : ($user->lang['GUEST'] . ': ' . $commentrow['comment_username']), $commentrow['user_colour']),

				'RANK_TITLE'			=> $commentrow['rank_title'],
				'RANK_IMG'				=> $commentrow['rank_image'],
				'RANK_IMG_SRC'			=> $commentrow['rank_image_src'],
				'SIGNATURE'				=> $commentrow['user_sig'],
			));
		}
		$db->sql_freeresult($result);

		$template->assign_vars(array(
			'DELETE_IMG'		=> $user->img('icon_post_delete', 'DELETE_COMMENT'),
			'EDIT_IMG'			=> $user->img('icon_post_edit', 'EDIT_COMMENT'),
			'INFO_IMG'			=> $user->img('icon_post_info', 'IP'),
			'MINI_POST_IMG'		=> $user->img('icon_post_target_unread', 'COMMENT'),
			'PROFILE_IMG'		=> $user->img('icon_user_profile', 'READ_PROFILE'),
			'PAGE_NUMBER'		=> sprintf($user->lang['PAGE_OF'], (floor($start / $config['posts_per_page']) + 1), ceil($image_data['image_comments'] / $config['posts_per_page'])),
			'PAGINATION'		=> generate_pagination(append_sid("{$phpbb_root_path}{$gallery_root_path}image_page.$phpEx", "album_id=$album_id&amp;image_id=$image_id&amp;sort_order=$sort_order"), $image_data['image_comments'], $config['posts_per_page'], $start),
		));
	}
}

page_header($user->lang['VIEW_IMAGE'] . ' - ' . $image_data['image_name']);

$template->set_filenames(array(
	'body' => 'gallery/viewimage_body.html')
);

page_footer();

?>