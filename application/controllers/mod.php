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
		$scanned = 0;
		$added_mods = 0;
		$added_versions = 0;
		$invalid_mods = 0;
		$invalid_versions = 0;
		foreach ($directory as $subdir) {
			$slug = $subdir->getFilename();
			try {
				if ($subdir->isDot()) {
					continue;
				}
				if (!$subdir->isDir()) {
					throw new Exception("Not a directory");
				}

				$mod = Mod::where("name", "=", $slug)->first();
				if (is_null($mod)) {
					$mod = new Mod();
					$mod->name = $slug;
					$mod->pretty_name = ucwords(str_replace(array("_", "-"), " ", $slug));
					$mod->author = "";
					$mod->description = "";
					$mod->link = "";
					$mod->save();
					++$added_mods;
				}
				++$scanned;

				// Remove existing versions
				ModVersion::where("mod_id", "=", $mod->id)->delete();

				// Add versions
				$moddir = new DirectoryIterator($subdir->getPathname());
				foreach ($moddir as $archive) {
					try {
						if ($archive->isDot()) {
							continue;
						}
						if (!$archive->isFile()
							or substr($archive->getFilename(), 0, strlen($slug)) !== $slug
							or substr($archive->getFilename(), -4) !== ".zip") {
							throw new Exception("Invalid mod archive: " . $archive->getFilename());
						}
						$version = substr($archive->getFilename(), strlen($slug) + 1, -4);

						$ver = new ModVersion();
						$ver->mod_id = $mod->id;
						$ver->version = $version;
						if ($md5 = $this->mod_md5($mod,$version)) {
							$ver->md5 = $md5;
						} else {
							throw new Exception("Failed to get MD5 for " . $archive->getFilename());
						}
						$ver->save();

						++$added_versions;
					} catch (Exception $e) {
						Log::warning("Invalid version: ".$slug.": " . $e->getMessage());
						++$invalid_versions;
					}
				}
			} catch (Exception $e) {
				Log::warning("Invalid mod: " . $slug . ": " . $e->getMessage());
				++$invalid_mods;
			}
		}
		// @todo Provide better errors
		return Redirect::back()->with('success', $scanned .' mod(s) scanned, '.$added_mods.' added, '.$invalid_mods.' invalid. '
			. $added_versions.' version(s) added, '.$invalid_versions.' invalid.');
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
