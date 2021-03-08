<?php
/**
 * Gives user permission to access a private category
 * @param unknown $user_id
 * @param unknown $cat_id
 * @return true success
 */
function sharealbum_grant_private_category($user_id,$cat_id) {
	if (pwg_query("
  		INSERT INTO `".USER_ACCESS_TABLE."` (`user_id`,`cat_id`)
  		VALUES (".$user_id.",".$cat_id.")
  	")) {
		return true;	
	} else {
		return false;
	}
}

/**
 * Creates a new user for being used with an album share
 * @param string $username Username
 * @param int $password_length Password length
 * @return int user_id in case of success | -1 in case of error
 */
function sharealbum_register_user($username,$password_length) {
	global $page;
	$new_user_id = -1;
	$new_user_id = register_user($username,sharealbum_generate_code($password_length, false,true),null,0,$page['errors'],false);
	// Sets user status to generic
	pwg_query("
	      UPDATE `".USER_INFOS_TABLE."`
	      SET `status` = 'generic'
	      WHERE `user_id` = ".$new_user_id.";
	");
	// Put the user in the sharealbum group
	pwg_query("
			INSERT INTO `".USER_GROUP_TABLE."` (group_id, user_id)
			SELECT g.id, ".$new_user_id."
			FROM `".GROUPS_TABLE."` g
			WHERE g.name like 'sharealbum'
			LIMIT 1
	");
	return $new_user_id;
}

/**
 * Get the share code for a specified album (category)
 * Returns the album code or null if not found
 * @param unknown $cat
 * @return Ambigous <NULL, unknown>
 */
function sharealbum_get_share_code($cat)
{
	$code = NULL;
	// Check if category is already shared using ShareAlbum plugin
	$result = pwg_query("
				SELECT `id`,`code`
				FROM `".SHAREALBUM_TABLE."`
				WHERE
					`cat` = ".$cat
	);
	if (pwg_db_num_rows($result))
	{
		// Existing code found for this album
		$row = pwg_db_fetch_assoc($result);
		$code = $row['code'];
	}
	return $code;
}

/**
 * Generates a random code of the desired length.
 * @param int $len Target length of the desired code
 * @param boolean $lower true to lowercase the generated code
 * @param boolean $use_special_chars true to use special characters
 * @return string
 */
function sharealbum_generate_code($len,$lower,$use_special_chars) {
	$chars = "abcdefghijkmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ023456789";
	$special_chars = "<>!+%&/()=?`!$.,;-";
	if ($use_special_chars)
	{
		$chars = $chars.$special_chars;
	}
	srand((double)microtime()*1000000);
	$i = 0;
	$code = '' ;

	while ($i < $len) {
		$num = rand() % 33;
		$tmp = substr($chars, $num, 1);
		$code = $code . $tmp;
		$i++;
	}
	if ($lower) {
		$code = strtolower($code);
	}
	return $code;
}

/**
 * 
 * @param unknown $group_name
 * @return Ambigous <number, unknown>
 */
function sharealbum_get_group_id($group_name) {
	$group_id = -1;
	$result = pwg_query("
			SELECT `id`
			FROM ".GROUPS_TABLE." 
			WHERE `name`='".$group_name."'"
			);
	if (pwg_db_num_rows($result))
	{
		// Existing code found for this album
		$row = pwg_db_fetch_assoc($result);
		$group_id = $row['id'];
	}
	return $group_id;
}

/**
 * Returns the absolute url to be shared for a defined code
 * @param unknown $group_name
 * @return Ambigous <number, unknown>
 */
function sharealbum_get_shareable_url($code) {
	return $r_url = get_absolute_root_url()."?".SHAREALBUM_URL_AUTH."=".$code;
}

/**
 * Remove a share on an album
 * @cat_id id of the album on which a share is applied
 */
function sharealbum_cancel_share($cat_id) {
	// List declared shares on this category (should be only one)
	$res = pwg_query("
		SELECT `id`,`user_id`
		FROM `".SHAREALBUM_TABLE."`
		WHERE `cat`=".$cat_id
	);
	while ($row = pwg_db_fetch_assoc($res)) {
		// Remove user permission on category
		pwg_query("
			DELETE FROM `".USER_ACCESS_TABLE."`
			WHERE `user_id`=".$row['user_id']." 
			AND `cat_id`=".$cat_id."
			LIMIT 1"
		);
		// Remove user infos from user_infos tables
		pwg_query("
			DELETE FROM `".USER_INFOS_TABLE."`
			WHERE `user_id`=".$row['user_id']."
			LIMIT 1"
		);
		// Remove group membership
		pwg_query("
			DELETE FROM `".USER_GROUP_TABLE."`
			WHERE `user_id`=".$row['user_id']."
			LIMIT 1"
		);
		// Delete user
		pwg_query("
			DELETE FROM `".USERS_TABLE."`
			WHERE `id`=".$row['user_id']."
			LIMIT 1"
		);
	};
	// Remove code from sharealbum table
	pwg_query("
		DELETE FROM `".SHAREALBUM_TABLE."`
		WHERE `cat`=".$cat_id."
		LIMIT 1"
	);
	// Remove any existing log from sharealbum_log table
	pwg_query("
		DELETE FROM `".SHAREALBUM_TABLE_LOG."`
		WHERE `cat_id`=".$cat_id
	);
}

/**
 * Renew the share code for an album
 * @cat_id id of the album on which a share is applied
 */
function sharealbum_renew_share($cat_id) {
	// Renewal of a link
	$new_code = "";
	do {
		$new_code = sharealbum_generate_code(SHAREALBUM_KEY_LENGTH,true,false);
	} while ($new_code == sharealbum_get_share_code($cat_id));
	if (!pwg_query("
	  UPDATE `".SHAREALBUM_TABLE."`
	  SET `code` = '".$new_code."',`creation_date`='".date("Y-m-d H:i:s")."'  
	  WHERE `cat` = ".$cat_id
	)) die('Could not update code');
}

/**
 * Creates a share for an album
 * @cat_id id of the album on which a share is applied
 */
function sharealbum_create($cat_id) {
	// Generate a unique (and unused) code
	$new_code = "";
	do {
		$new_code = sharealbum_generate_code(SHAREALBUM_KEY_LENGTH,false,false);
	} while ($new_code == sharealbum_get_share_code($cat_id));
	
	// Determine user name
	$sharealbum_new_user = "";
	do {
		$sharealbum_new_user = SHAREALBUM_USER_PREFIX.sharealbum_generate_code(SHAREALBUM_USER_CODE_SUFFIX_LENGTH,true,false);
	} while (!empty(validate_login_case($sharealbum_new_user)));
	// Register user
	$new_user_id = sharealbum_register_user($sharealbum_new_user,sharealbum_generate_code(SHAREALBUM_USER_PASSWORD_LENGTH, false,true));
	if (sharealbum_grant_private_category($new_user_id,$cat_id)) {
			// TODO handle insertion error
			// Insert code into sharealbum table
			pwg_query("
				INSERT INTO `".SHAREALBUM_TABLE."` (`cat`,`user_id`,`code`,`creation_date`)
				VALUES (".$cat_id.",".$new_user_id.",'".$new_code."','".date("Y-m-d H:i:s")."')
			");
		}
		
	 // Set user privacy level to lowest level of images in share album
	 // Get minimum privacy level
	 $result = pwg_query("
		  SELECT MIN(`level`) AS min_level
		  FROM ".IMAGES_TABLE."
		  WHERE `storage_category_id` = '".$cat_id."'
	 ");


	if (pwg_db_num_rows($result)) {
		$row = pwg_db_fetch_assoc($result);
		$privacy_level = $row['min_level'];
		
		if ($privacy_level != null) {
			// Set user level in USER_INFOS_TABLE
			pwg_query("
				UPDATE `".USER_INFOS_TABLE."`
				SET `level` = ".$privacy_level."
				WHERE `user_id` = ".$new_user_id);
		}
	}
}

/**
 * Gets the full path for an album (with the upper categories names)
 * @cat_name name of the album (without uppercats)
 * @uppercats categories list (xx,xx,xx)
 */
function sharealbum_getname_with_uppercats($cat_name, $uppercats) {
	$separator = " / ";
	
	$arr_uppercats = array();
	$arr_uppercats = explode(",",$uppercats);
	
	$name_with_uppercats = "";
	foreach ($arr_uppercats as $cat) {
		$result = pwg_query("
			SELECT `name`
			FROM ".CATEGORIES_TABLE." 
			WHERE `id`='".$cat."'"
			);
		if (pwg_db_num_rows($result))
		{	
			$row = pwg_db_fetch_assoc($result);
			$name_with_uppercats .= $row['name'];
			if ($cat != end($arr_uppercats)) {
				$name_with_uppercats .= $separator;
			}
		}
	}
	return $name_with_uppercats;
}
?>