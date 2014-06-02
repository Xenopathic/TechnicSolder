<?php

class Mod_Controller extends Base_Controller {

	public function __construct()
	{
		parent::__construct();
		$this->filter('before','auth');
		$this->filter('before', 'perm', array('solder_mods'));
		$this->filter('before', 'perm', array('mods_manage'))
			->only(array('view','versions'));
		$this->filter('before', 'perm', array('mods_create'))
			->only(array('create'));
		$this->filter('before', 'perm', array('mods_delete'))
			->only(array('delete'));
	}

	public function action_list()
	{
		$mods = Mod::all();
		return View::make('mod.list')->with(array('mods' => $mods));
	}

	public function action_view($mod_id = null)
	{
		if (empty($mod_id))
			return Redirect::to('mod/list');

		$mod = Mod::find($mod_id);
		if (empty($mod))
			return Redirect::to('mod/list');

		return View::make('mod.view')->with(array('mod' => $mod));
	}

	public function action_create()
	{
		return View::make('mod.create');
	}

	public function action_do_create()
	{
		$rules = array(
			'name' => 'required|unique:mods',
			'pretty_name' => 'required'
			);
		$messages = array(
			'name_required' => 'You must fill in a mod slug name.',
			'name_unique' => 'The slug you entered is already taken',
			'pretty_name_required' => 'You must enter in a mod name'
			);

		$validation = Validator::make(Input::all(), $rules, $messages);
		if ($validation->fails())
			return Redirect::back()->with_errors($validation->errors);

		try {
			$mod = new Mod();
			$mod->name = Str::slug(Input::get('name'));
			$mod->pretty_name = Input::get('pretty_name');
			$mod->author = Input::get('author');
			$mod->description = Input::get('description');
			$mod->link = Input::get('link');
			$mod->save();
			return Redirect::to('mod/view/'.$mod->id);
		} catch (Exception $e) {
			Log::exception($e);
		}
	}

	public function action_delete($mod_id = null)
	{
		if (empty($mod_id))
			return Redirect::to('mod/list');

		$mod = Mod::find($mod_id);
		if (empty($mod))
			return Redirect::to('mod/list');

		return View::make('mod.delete')->with(array('mod' => $mod));
	}

	public function action_do_modify($mod_id = null)
	{
		if (empty($mod_id))
			return Redirect::to('mod/list');

		$mod = Mod::find($mod_id);
		if (empty($mod))
			return Redirect::to('mod/list');

		$rules = array(
			'pretty_name' => 'required',
			'name' => 'required|unique:mods,name,'.$mod->id,
			);

		$messages = array(
			'pretty_name_required' => 'You must enter in a Mod Name',
			'name_required' => 'You must enter a Mod Slug',
			'name_unique' => 'The slug you entered is already in use by another mod',
			);

		$validation = Validator::make(Input::all(), $rules, $messages);
		if ($validation->fails())
			return Redirect::back()->with_errors($validation->errors);

		try {
			$mod->pretty_name = Input::get('pretty_name');
			$mod->name = Input::get('name');
			$mod->author = Input::get('author');
			$mod->description = Input::get('description');
			$mod->link = Input::get('link');
			$mod->save();

			return Redirect::back()->with('success','Mod successfully edited.');
		} catch (Exception $e) {
			Log::exception($e);
		}
	}

	public function action_do_delete($mod_id = null)
	{
		if (empty($mod_id))
			return Redirect::to('mod/list');

		$mod = Mod::find($mod_id);
		if (empty($mod))
			return Redirect::to('mod/list');

		foreach ($mod->versions as $ver)
		{
			$ver->builds()->delete();
			$ver->delete();
		}
		$mod->delete();

		return Redirect::to('mod/list')->with('deleted','Mod deleted!');
	}

	public function action_versions($mod_id = null)
	{
		if (empty($mod_id))
			return Redirect::to('mod/list');

		$mod = Mod::find($mod_id);
		if (empty($mod))
			return Redirect::to('mod/list');

		return View::make('mod.versions')->with(array('mod' => $mod));
	}

	public function action_rehash($ver_id = null)
	{
		if (empty($ver_id))
			return Redirect::to('mod/list');

		$ver = ModVersion::find($ver_id);
		if (empty($ver))
			return Redirect::to('mod/list');

		if ($md5 = $this->mod_md5($ver->mod,$ver->version))
		{
			$ver->md5 = $md5;
			$ver->save();
			return Response::json(array(
								'version_id' => $ver->id,
								'md5' => $md5,
								));
		}

		return Response::error('500');
	}

	public function action_addversion()
	{
		$mod_id = Input::get('mod-id');
		$version = Input::get('add-version');
		if (empty($mod_id) || empty($version))
			return Response::json(array(
						'status' => 'error',
						'reason' => 'Missing Post Data'
						));

		$mod = Mod::find($mod_id);
		if (empty($mod))
			return Response::json(array(
						'status' => 'error',
						'reason' => 'Could not pull mod from database'
						));

		$ver = new ModVersion();
		$ver->mod_id = $mod->id;
		$ver->version = $version;
		if ($md5 = $this->mod_md5($mod,$version))
		{
			$ver->md5 = $md5;
			$ver->save();
			return Response::json(array(
						'status' => 'success',
						'version' => $ver->version,
						'md5' => $ver->md5,
						));
		} else {
			return Response::json(array(
						'status' => 'error',
						'reason' => 'Could not get MD5. URL Incorrect?'
						));
		}
	}

	public function action_deleteversion($ver_id = null)
	{
		if (empty($ver_id))
			return Redirect::to('mod/list');

		$ver = ModVersion::find($ver_id);
		if (empty($ver))
			return Redirect::to('mod/list');

		$old_id = $ver->id;
		$ver->delete();
		return Response::json(array('version_id' => $old_id));
	}

	public function action_do_rescanmods()
	{
		$mods_path = Config::get('solder.repo_location').'mods/';
		if (!is_dir($mods_path)) {
			Redirect::back()->with('error', 'Cannot find mods directory in the local filesystem, cannot scan remote repo_location');
		}
		if (!extension_loaded('zip')) {
			Redirect::back()->with('error', 'PHP ZIP extensions not loaded it is required to open mods');
		}
		// For now it only can scan mods stored locally
		$directory = new DirectoryIterator($mods_path);
		$files_scanned = 0;
		$mods_skipped = 0;
		$mods_added = 0;
		foreach ($directory AS $file) {
			$status = null;
			if ($file->isDot() || $file->isLink()) {
				continue;
			}
			if ($file->isDir()) {
				$sub_directory = new DirectoryIterator($file->getPathname());
				foreach($sub_directory AS $sub_file) {
					if ($sub_file->isDot() || $sub_file->isLink()) {
						continue;
					}
					if (!$sub_file->isDir()) {
						$status = $this->mod_rescan_validate_file($sub_file);
					}
				}
				unset($sub_directory);
			} else {
				$status = $this->mod_rescan_validate_file($file);
			}
			if (!is_null($status)) {
				$files_scanned++;
				if ($status === true) {
					$mods_added++;
				} else if ($status === false) {
					$mods_skipped++;
				}
			}
		}
		// @todo Provide better errors
		return Redirect::back()->with('success', $files_scanned .' file(s) scanned, '.$mods_added.' added to the system and '.$mods_skipped.' mod(s) skipped');
	}

	private function mod_rescan_validate_file(DirectoryIterator $file) {
		if ($file->isFile() && ($file->getExtension() == "zip" || $file->getExtension() == "jar")) {
			$zip = new ZipArchive();
			if ($zip->open($file->getPathname(), ZipArchive::CREATE) !== true) {
				return "";
			}
			$json = $zip->getFromName("mcmod.info");
			$zip->close();
			unset($zip);
			if ($json === false || !is_string($json)) {
				return "";
			}
			$config = json_decode($json, true);
			if ($config === false || !is_array($config)) {
				return "";
			}
			if (is_numeric(key($config))) {
				$config = $config[key($config)];
			} else if (isset($config['modlist']) && is_array($config['modlist']) && count($config['modlist']) >= 1) {
				$config = $config['modlist'][key($config['modlist'])];
			} else {
				// Skip because if wonky config json
				return false;
			}
			if (!isset($config['name']) || !isset($config['version'])) {
				return "";
			}
			$save_name = Str::slug(strtolower(str_replace(' ', '-', $config['name'])));
			$save_version = strtolower(str_replace(' ', '-', $config['version']));
			if (!strlen($save_name) || !strlen($save_version)) {
				return "";
			}
			$save_path = $this->mod_generate_path($save_name, $save_version).'.'.$file->getExtension();
			if ($file->getPathname() != $save_path) {
				// Prevent overwrite
				if (file_exists($save_path)) {
					return "";
				}
				if (!is_dir(dirname($save_path))) {
					if (!mkdir(dirname($save_path))) {
						return "";
					}
				}
				if (!rename($file->getPathname(), $save_path)) {
					return "";
				}
			}
			try {
				// Don't account for updating at this point
				// @todo handle updates
				if (Mod::where('name', 'like', '%'.$save_name.'%')->count() > 0) {
					return false;
				}
			} catch(Exception $ex) {
			}
			try {
				// Add Mod
				$mod = new Mod();
				$mod->name = $save_name;
				$mod->pretty_name = $config['name'];
				$mod->author = (isset($config['authorList']) && is_array($config['authorList'])?implode(', ', $config['authorList']):'');
				$mod->description = (isset($config['description'])?$config['description']:'');
				$mod->link = (isset($config['url'])?$config['url']:'');
				$mod->save();
				// Add ModVersion
				$ver = new ModVersion();
				$ver->mod_id = $mod->id;
				$ver->version = $save_version;
				if ($md5 = $this->mod_md5($mod,$save_version)) {
					$ver->md5 = $md5;
					$ver->save();
				}
				return true;
			} catch(Exception $ex) {
				return "";
			}
		}
		return "";
	}

	/**
	 * Generates a mod path without an extension
	 */
	private function mod_generate_path($mod_name, $version)
	{
		return Config::get('solder.repo_location').'mods/'.$mod_name.'/'.$mod_name.'-'.$version;
	}

	private function mod_discover_path($mod_name, $version)
	{
		$location = $this->mod_generate_path($mod_name, $version);
		// Check Local
		if (file_exists($location.'.zip')) {
			return $location.'.zip';
		}
		if (file_exists($location.'.jar')) {
			return $location.'.jar';
		}
		// Check Remote
		if (UrlUtils::check_remote_file($location.'.zip')) {
			return $location.'.zip';
		}
		if (UrlUtils::check_remote_file($location.'.jar')) {
			return $location.'.jar';
		}
		Log::write("ERROR", "Could not find mod located at: " . $location );
		return "";
	}
	
	private function mod_md5($mod, $version, $location = null)
	{
		if (is_null($location)) {
			$location = $this->mod_discover_path($mod->name, $version);
		}
		if ($location == "") {
			return "";
		}
		if (file_exists($location))
			return md5_file($location);
		else {
			return $this->remote_mod_md5($mod, $version, 0, $location);
		}
	}

	private function remote_mod_md5($mod, $version, $attempts = 0, $location = null)
	{
		if (is_null($location)) {
			$location = $this->mod_discover_path($mod->name, $version);
		}
		if ($attempts >= 3)
		{
			Log::write("ERROR", "Exceeded maximum number of attempts for remote MD5 on mod ". $mod->name ." version ".$version." located at ". $url);
			return "";
		}

		$hash = UrlUtils::get_remote_md5($url);
		if ($hash != "")
			return $hash;
		else {
			Log::write("ERROR", "Attempted to remote MD5 mod " . $mod->name . " version " . $version . " located at " . $url ." but curl response did not return 200!");
			return $this->remote_mod_md5($mod, $version, $attempts + 1, $location);
		}
	}
}