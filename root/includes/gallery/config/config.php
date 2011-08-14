<?php
/**
*
* @package phpBB Gallery
* @version $Id$
* @copyright (c) 2011 nickvergessen nickvergessen@gmx.de http://www.flying-bits.org
* @license http://opensource.org/licenses/gpl-license.php GNU Public License
*
*/

/**
* @ignore
*/

if (!defined('IN_PHPBB'))
{
	exit;
}

class phpbb_gallery_config
{
	/**
	* Prefix which is prepend to the configs before they are stored in the config table.
	*/
	static private $prefix = 'phpbb_gallery_';

	static private $config = false;

	static private $loaded = false;

	static public function get($key)
	{
		if (self::$loaded === false)
		{
			self::load();
		}

		return self::$config[$key];
	}

	static public function get_array()
	{
		if (self::$loaded === false)
		{
			self::load();
		}

		return self::$config;
	}

	static public function get_default()
	{
		return self::$default_config;
	}

	static public function set($config_name, $config_value)
	{
		settype($config_value, gettype(self::$default_config[$config_name]));
		self::$config[$config_name] = $config_value;

		if ((gettype(self::$default_config[$config_name]) == 'bool') || (gettype(self::$default_config[$config_name]) == 'boolean'))
		{
			$update_config = (self::$config[$config_name]) ? '1' : '0';
			set_config(self::$prefix . $config_name, $update_config, self::is_dynamic($config_name));
		}
		else
		{
			set_config(self::$prefix . $config_name, self::$config[$config_name], self::is_dynamic($config_name));
		}
	}

	static public function inc($config_name, $increment)
	{
		if ((gettype(self::$default_config[$config_name]) != 'int') && (gettype(self::$default_config[$config_name]) != 'integer'))
		{
			return false;
		}

		set_config_count(self::$prefix . $config_name, (int) $increment, self::is_dynamic($config_name));
		self::$config[$config_name] += (int) $increment;
		return true;
	}

	static public function dec($config_name, $decrement)
	{
		if ((gettype(self::$default_config[$config_name]) != 'int') && (gettype(self::$default_config[$config_name]) != 'integer'))
		{
			return false;
		}

		set_config_count(self::$prefix . $config_name, 0 - (int) $decrement, self::is_dynamic($config_name));
		self::$config[$config_name] -= (int) $decrement;
		return true;
	}

	static public function is_dynamic($config_name)
	{
		if (isset(self::$is_dynamic[$config_name]))
		{
			return true;
		}
		return false;
	}

	static public function exists($key)
	{
		if (self::$loaded === false)
		{
			self::load();
		}

		return !empty(self::$config[$key]);
	}

	static public function load($load_default = false)
	{
		global $config, $cache;

		self::load_configs('core');

		// Load the configs of the available plugins
		if (($plugins = $cache->get('_gallery_config_plugins')) === false)
		{
			$dir = @opendir(phpbb_gallery_url::_return_file('plugins/', 'phpbb', 'includes/gallery/config/'));
			if ($dir)
			{
				global $phpEx;

				while (($entry = readdir($dir)) !== false)
				{
					if (substr(strrchr($entry, '.'), 1) == $phpEx)
					{
						$plugins[] = substr(basename($entry), 0, -(strlen($phpEx) + 1));
					}
				}
				closedir($dir);
			}
			$cache->put('_gallery_config_plugins', $plugins);
		}


		foreach ($plugins as $plugin)
		{
			self::load_configs($plugin);
		}

		foreach ($config as $config_name => $config_value)
		{
			// Load all config values of the gallery
			if (strpos($config_name, self::$prefix) === 0)
			{
				$config_name = substr($config_name, strlen(self::$prefix));
				settype($config_value, gettype(self::$default_config[$config_name]));
				self::$config[$config_name] = $config_value;
			}
		}

		if ($load_default)
		{
			// Should we load the default-config?
			self::$config = self::$config + self::$default_config;
		}

		self::$loaded = true;
	}

	static private function load_configs($plugin_name)
	{
		if ($plugin_name == 'core')
		{
			$class_name = 'phpbb_gallery_config_core';
		}
		elseif (class_exists('phpbb_gallery_config_plugins_' . $plugin_name))
		{
			$class_name = 'phpbb_gallery_config_plugins_' . $plugin_name;
		}
		else
		{
			global $user;
			$user->add_lang('mods/gallery');
			trigger_error($user->lang('PLUGIN_CLASS_MISSING', 'phpbb_gallery_config_plugins_' . $plugin_name));
		}

		foreach ($class_name::$configs as $name => $value)
		{
			self::$default_config[$class_name::$prefix . $name] = $value;
		}

		foreach ($class_name::$is_dynamic as $name)
		{
			self::$is_dynamic[] = $class_name::$prefix . $name;
		}
	}

	static public function install()
	{
		self::load_configs('core');

		foreach (self::$default_config as $name => $value)
		{
			self::set($name, $value);
		}
	}

	static private $is_dynamic = array();

	static private $default_config = array();
}
