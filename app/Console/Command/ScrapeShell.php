<?php
class ScrapeShell extends AppShell {
	var $uses = array('AnimeSynonym', 'ScrapeInfo', 'Anime', 'AnimeGenre', 'Episode', 'Genre', 'AnimeRelationship');
	var $stats = array(
		'Anime_changed' => 0,
		'Episodes_changed' => 0,
		'Genre_linked' => 0,
		
		);
	function main(){
		App::uses('Sanitize', 'Utility');
		define('CLIENTNAME','calendar');
		define('CLIENTVERSION','1');
		define('SCRAPEDEBUG',TRUE);

		// Variables to show at the end..

		// Fetch all the items in the queue

		$queue = $this->ScrapeInfo->find('all',array(
													'conditions' => array(
														'scrape_id !=' => 'NULL',
														'scrape_source !=' => 'NULL',
														'scrape_needed' => '1'
														)
													)
										);
		foreach($queue as $item)
		{
			$source = $item['ScrapeInfo']['scrape_source'];
			$this->out('Fetching item: ' . $item['Anime']['title'] . " from source: " . $source);

			if($source == 'thetvdb')
			{
				$this->thetvdbScrape($item);
			} else if($source == 'mal')
			{
				$this->malScrape($item);
			} else if($source == 'anidb')
			{
				$this->anidbScrape($item);
			}

			// Remove the scrape_needed on the queue
			//$item->set('scrape_needed', NULL);
			//$item->ScrapeInfo->save($item);
			$this->ScrapeInfo->id = $item['ScrapeInfo']['id'];
			$this->ScrapeInfo->read();
			$this->ScrapeInfo->set('scrape_needed',NULL);
			$this->ScrapeInfo->save();
			foreach($this->stats as $key => $value)
				$this->out("\t" . $key . " => " . $value);
			//print_r($this->stats);
			$this->stats = array();
			$this->out('Finished item..');
			$this->out('----------------');
			
		}
		
		$this->out('Finished parsing through queue');
	}
	
	function malScrape($item){
		// Using mal-api.com for fetching
		// Used for genres.
		$url = "http://mal-api.com/anime/";
		$json = file_get_contents( $url . $item['ScrapeInfo']['scrape_id'] );
		
		$anime = json_decode($json, TRUE);
		
		if($item['ScrapeInfo']['fetch_information'] == '1')
		{
			if(SCRAPEDEBUG)
				$this->out("\t" . "\t" . 'Got information');

			//$this->Anime->id = $item['ScrapeInfo']['anime_id'];
			$dbAnime = $this->Anime->read(NULL, $item['ScrapeInfo']['anime_id']);
			
			/* CHECK IF THE ANIME GOT DESCRIPTION OR STATUS BEFOREHAND */
			if($dbAnime['Anime']['desc'] == '' || $dbAnime['Anime']['desc'] == NULL)
			{
				if(SCRAPEDEBUG)
					$this->out("\t" ."\t" . "\t" . 'Adding new synopsis/description to anime:' . $dbAnime['Anime']['title'] . ' Desc:' . $anime['synopsis']);
				$this->Anime->set('desc',$anime['synopsis']);
				if($this->Anime->save())
					$this->stats['Anime_changed']++;
			}
			$status = array(
				'finished airing' => 'finished',
				'currently airing' => 'currently',
				'not yet aired' => 'unaired'
			);
			if($dbAnime['Anime']['status'] != $status[$anime['status']] || $dbAnime['Anime']['status'] == NULL || $dbAnime['Anime']['status'] == '')
			{
				if(SCRAPEDEBUG)
					$this->out("\t" ."\t" . "\t" . 'Adding new status to anime:' . $dbAnime['Anime']['title'] .'.'. ' Status:' . $dbAnime['Anime']['status'] . '->' . $status[$anime['status']]);

				$this->Anime->set('status',$status[$anime['status']]);
				if($this->Anime->save())
					$this->stats['Anime_changed']++;
			}

			if($dbAnime['Anime']['runtime'] == '' || $dbAnime['Anime']['runtime'] == null)
			{
				/** TODO: Implement runtime fetching from mal **/
			}

			// Fetch anime rating PG-13 etc..
			$classification = array(
				'G - All Ages' => 'G',
				'PG - Children' => 'PG',
				'PG-13 - Teens 13 or older' => 'PG-13',
				'R - 17+ (violence & profanity)' => 'R',
				'R+ - Mild Nudity' => 'R+',
				'Rx - Hentai' => 'Rx'
				);
			if(($dbAnime['Anime']['classification'] == NULL || $dbAnime['Anime']['classification'] != $anime['classification'])
				&& isset($classification[$anime['classification']]))
			{
				$this->Anime->set('classification',$classification[$anime['classification']]);
				if($this->Anime->save())
					$this->stats['Anime_changed']++;
				if(SCRAPEDEBUG)
					$this->out("\t" . "\t" . 'Changing classification from: "'. $dbAnime['Anime']['classification'] .'" to "'. $classification[$anime['classification']].'"' );
			}
			


			if(SCRAPEDEBUG)
				$this->out("\t" . "\t" . 'Got genres: ' . implode(',',$anime['genres']));
			
			foreach($anime['genres'] as $genre)
			{
				$this->addGenre($item, $genre,'');
			}
			foreach($anime['tags'] as $tag)
			{
				$this->addGenre($item, $tag,'');
			}

			if(SCRAPEDEBUG)
				$this->out("\t" . "\t" . 'Fetching information about relationships');
			foreach($anime['prequels'] as $prequel)
			{
				// Fetch our animeid from mal id
				if(($animeid = $this->getAnimeId($prequel['anime_id'])) == null)
				{
					if(SCRAPEDEBUG)
						$this->out("\t" . "\t" . "\t" . 'The anime "' . $prequel['title'] . '" with myanimelist id "' .$prequel['anime_id']. '" does not exists in the db. Skipping...');
					continue;
				}
				
				$this->addRelationship($item['ScrapeInfo']['anime_id'],'sequel',$animeid);
				
			}
			foreach($anime['sequels'] as $sequel)
			{
				// Fetch our animeid from mal id
				if(($animeid = $this->getAnimeId($sequel['anime_id'])) == null)
				{
					if(SCRAPEDEBUG)
						$this->out("\t" . "\t" . "\t" . 'The anime "' . $sequel['title'] . '" with myanimelist id "' .$sequel['anime_id']. '" does not exists in the db. Skipping...');
					continue;
				}
				
				$this->addRelationship($animeid,'sequel',$item['ScrapeInfo']['anime_id']);

			}
			if(SCRAPEDEBUG)
				$this->out("\t" . "\t" . 'Fetching synonyms');
			$languages = array(
				'english' => 'en'
				);
			foreach($anime['other_titles'] as $lang => $synonyms)
			{
				if(array_key_exists($lang,$languages))
					foreach($synonyms as $synonym)
						$this->addSynonym( $item, $synonym, $languages[$lang] );
			}
			$this->addSynonym( $item, $anime['title'] ,'x-jat');
		}
		
	}

	function getAnimeId($malId = null)
	{
		if($malId == null)
			return null;

		$result = $this->ScrapeInfo->find('first',array(
			'conditions' => array(
				'scrape_id' => $malId,
				'scrape_source' => 'mal'
			)
		));

		if(count($result['ScrapeInfo']) == 0)
			return null;
		return $result['ScrapeInfo']['anime_id'];

	}

	/***
	 * RETURN TRUE IF ADDED; FALSE OTHERWISE
	 * params
	 * 	anime1 = anime id for anime #1
	 * 	anime2 = anime id for anime #2
	 * 	type = type of relationship between anime1 and anime2
	 *
	 * Eg.
	 * 	animeid 15 is a sequel of animeid 10
	 * 	anime1 = 15, type = sequel, anime2 = 10
	 */
	function addRelationship($anime1 = null, $type = null, $anime2 = null)
	{
		if($anime1 == null || $anime2 == null || $type == null)
			return false;

		// Check if this relationship already exists;
		//
		$exists = $this->AnimeRelationship->find('count', array(
				'conditions' => array(
					'Anime1' => $anime1,
					'Anime2' => $anime2,
					'type'	=> $type
				)
			)
		);

		if($exists !== 0)
		{
			if(SCRAPEDEBUG)
				$this->out("\t" ."\t" . "\t" . 'Anime "'.$anime1.'" is already linked up with "'.$anime2.'" as "'.$type.'". Nothing done' );
			return false;
		}

		$this->AnimeRelationship->create();
		$this->AnimeRelationship->set('anime1',$anime1);
		$this->AnimeRelationship->set('type', $type);
		$this->AnimeRelationship->set('anime2',$anime2);
		$this->AnimeRelationship->save();
		$this->stats['Relationship_added']++;
		if(SCRAPEDEBUG)
			$this->out("\t" ."\t" . "\t" . 'Anime "'.$anime1.'" is now linked up with "'.$anime2.'" as "'.$type.'"' );

		return true;

	}

	private function addAnimeByMALid($id)
	{
		// Check if the anime already exists in the db
		
	}

	function in_array_r($needle, $keyinput, $haystack) {
		foreach ($haystack as $key => $item) {
			if ($item === $needle && $key==$keyinput || (is_array($item) && $this->in_array_r($needle, $keyinput, $item))) {
			return true;
			}
		}

		return false;
	}
	
	function addGenre($scrapeInfo, $genre, $description)
	{
		$genre = Sanitize::clean(trim($genre));
		$description = Sanitize::clean(trim($description));

		// Check if the genre is in the database.
		$this->Genre->recursive = -1;
		$ant = $this->Genre->find('first',array('conditions' => array('LOWER(name)' => strtolower($genre))));

		$genreid = $ant['Genre']['id'];
		
		if($ant == NULL){
			
			$this->Genre->create();
			$this->Genre->set('name',$genre);
			$this->Genre->set('description',$description);
			if($this->Genre->save())
			{
				$genreid = $this->Genre->getInsertID();
				$this->stats['Genre_added']++;
				if(SCRAPEDEBUG)
					$this->out("\t" . "\t" . 'Genre \''.$genre.'\' is added to the db....');
			}
			else{
				if(SCRAPEDEBUG)
					$this->out("\t" . "\t" . 'Could not add genre \''.$genre.'\' to the db....');
				return;
			}

		}
		else{
			$genreid = $ant['Genre']['id'];
		}
		
		if($ant['Genre']['description'] == '' && $description != '')
		{
			$this->Genre->read(NULL,$genreid);
			$this->Genre->set('description',$description);
			if($this->Genre->save()){
				$this->stats['Genre_changed']++;
				if(SCRAPEDEBUG)
					$this->out("\t" . "\t" . 'Added description to genre \''.$genre.'\'....');
			}
		}
		
		$anime_database = $this->Anime->read(NULL, $scrapeInfo['Anime']['id']);
		
		// Check if the genre is already connected to the anime
		if($this->in_array_r($genreid,'genre_id',$anime_database['AnimeGenre'])){
			if(SCRAPEDEBUG)
				$this->out("\t" . "\t" . 'Genre \''.$genre.'\' is already connected to anime in db. Skipping...');
			return;
		}
		//echo $genreid;
		//echo $scrapeInfo['Anime']['id'];
		// CHECK IF ANIME EXISTS!!
		// Connect the genre to the anime
		$tjos->AnimeGenre->recursive = -1;
		$this->AnimeGenre->create();
		$this->AnimeGenre->set('anime_id',$scrapeInfo['Anime']['id']);
		$this->AnimeGenre->set('genre_id',$genreid);
		$this->AnimeGenre->save();
		$this->stats['Genre_linked']++;
		if(SCRAPEDEBUG)
			$this->out("\t" . "\t" . 'Genre "'.$genre.'" is now linked up to the anime....');
		return;
	}

	function addSynonym($scrapeInfo, $synonym, $lang){
		$synonym = trim($synonym);
		//$synonyms = $this->AnimeSynonym->findAllByAnime_id($scrapeInfo['Anime']['id']);
		$this->AnimeSynonym->recursive = -1;
		$ant = $this->AnimeSynonym->find('count',array('conditions' => array('LOWER(AnimeSynonym.title)' => strtolower($synonym),'AnimeSynonym.anime_id' => $scrapeInfo['Anime']['id'])));
		if($ant != 0 || strtolower(trim($scrapeInfo['Anime']['title']) == strtolower($synonym)))
		{
			if(SCRAPEDEBUG)
				$this->out("\t" . "\t" . 'Synonym "'.$synonym.'" is already linked to the anime.... Nothing done');
			return;
		} 

		// Create the synonym and link it to the anime
		$this->AnimeSynonym->create();

		$this->AnimeSynonym->set('anime_id', $scrapeInfo['Anime']['id']);
		$this->AnimeSynonym->set('title', $synonym);
		$this->AnimeSynonym->set('lang', $lang);

		if($this->AnimeSynonym->save()){
			$this->stats['Synonym_added']++;
			if(SCRAPEDEBUG)
				$this->out("\t" . "\t" . 'Synonym "'.$synonym.'" are created and linked up to the anime.');
		}
	}
	
	function anidbScrape($item){
		
		// we try not to use the session client
		//$anidbSession = new anidb_Session($username, $password, $nat);
		//$port = 9001;
		$animeid = $item['ScrapeInfo']['scrape_id'];
		//$anidbURL = "http://api.anidb.net/httpapi?client=".CLIENTNAME."&clientver=".CLIENTVERSION."&protover=1&request=anime&aid=".$animeid;

		// Temporary server
		$anidbURL = "http://158.39.171.120/anidb/anidb.php?aid=".$animeid."&client=".CLIENTNAME."&version=".CLIENTVERSION;
		$port = 80;
		$sleepTime = 3;
		// Blocked access for this---- hmmm
		//$response = file_get_contents($anidbURL);
		//echo $anidbURL;
		$crl = curl_init();
		$timeout = 20;
		curl_setopt($crl, CURLOPT_URL,$anidbURL);
		curl_setopt($crl, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($crl, CURLOPT_ENCODING,'gzip');
		curl_setopt($crl, CURLOPT_HEADER,0);
		curl_setopt($crl, CURLOPT_FRESH_CONNECT, 1);
		curl_setopt($crl, CURLOPT_CONNECTTIMEOUT, $timeout);
		curl_setopt($crl, CURLOPT_PORT, $port);
		// This is just for godaddy
		//curl_setopt($crl, CURLOPT_PROXY, 'http://proxy.shr.secureserver.net:80');
		//curl_setopt($crl, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
		//curl_setopt($crl, CURLOPT_SSL_VERIFYPEER, false);
		//curl_setopt($crl, CURLOPT_VERBOSE, 1);
		//curl_setopt($crl, CURLOPT_FOLLOWLOCATION,1);

		$response = curl_exec($crl) or die(curl_error());
		//$response = file_get_contents($anidbURL);
		$anime = new SimpleXMLElement($response);
		curl_close($crl);
		
		// CHECK WHAT WE WANT TO FETCH
		//$this->out($anidbURL);
		if($item['ScrapeInfo']['fetch_episodes'] == '1')
		{
			// Ep type 1 = Regular Episode
			// Ep type 4 = Trailer/Promo/Ads
			if(SCRAPEDEBUG)
				$this->out("\t".'AnidbScraper is set to scrape episodes');
			// Parse through the episodes
			//if($anime->count() !== 0)
			if(count($anime->children()) !== 0) 
			foreach($anime->episodes->episode as $episode)
			{
				//print_r($episode);
				// Check if right episode type
				if((int)$episode->epno['type'] != 1)
					continue;

				// Fetch the episode name via namespace

				// Add the episode
				$path = $episode->xpath("title[@xml:lang='en']");
				$name = '';

				while(list( ,$node) = each($path)){
					$name = $node;
				}

				$special = NULL;
				//print_r($path);
				//print_r($episode);
				//$this->out($item['Anime']['id'].' '.$episode->epno.' '. $episode->airdate . ' ' . $name. ' ' . $special);
				$this->addEpisode( $item, $item['Anime']['id'], (string)$episode->epno, (string)$episode->airdate, $name,'', $special);
				
			}
			else
				if(SCRAPEDEBUG)
					$this->out("\t".'Xml size is 0.. Sorry');
			
		}

		// Define what kind of language we are fetching synonyms for
		$language = array(
				'en' => 'en',
				'x-jat' => 'x-jat'
			);

		if($item['ScrapeInfo']['fetch_information'] == '1')
		{
			if(SCRAPEDEBUG)
				$this->out("\t".'AnidbScraper is set to scrape genres');
			//if($anime->count() !== 0){
			if(count($anime->children()) !== 0){
				foreach($anime->categories->category as $category)
					$this->addGenre($item, (string)$category->name, (string)$category->description);
				foreach($anime->tags->tag as $tag)
					$this->addGenre($item, (string)$tag->name, (string)$tag->description);
			}
			else
				if(SCRAPEDEBUG)
					$this->out("\t". 'Xml size is 0.. Sorry');
			
			if(SCRAPEDEBUG)
				$this->out("\t".'AnidbScraper is set to fetch episode runtime');
			foreach($anime->episodes->episode as $episode)
			{
				// Check if right episode type
				if((int)$episode->epno['type'] != 1)
					continue;
				// Always prefer information from anidb..
				if($item['Anime']['runtime'] != $episode->length)
				{
					$this->Anime->read(NULL, $item['ScrapeInfo']['anime_id']);
					$this->Anime->set('runtime',$episode->length);
					if($this->Anime->save())
					{
						if(SCRAPEDEBUG)
							$this->out("\t" . "\t" . 'Anime "'.$item['Anime']['title'].'" now have runtime "'.$episode->length.'".');
					}
				}
				break;
			}

			if(SCRAPEDEBUG)
				$this->out("\t".'AnidbScraper is set to scrape synonyms');
			//if($anime->count() !== 0)
			if(count($anime->children()) !== 0)
			{
				foreach($language as $key => $value)
					foreach ($anime->titles->xpath('title[@xml:lang="'.$key.'"]') as $title)
				  		$this->addSynonym($item, (string)$title, $value);
			}
			else
				if(SCRAPEDEBUG)
					$this->out("\t". 'Xml size is 0.. Sorry');
		}
		if($item['ScrapeInfo']['fetch_images'] == '1')
		{

		}
		
		sleep(3);
		//print_r($response);
	}
	
	function thetvdbScrape($item){
		App::import('Vendor','Thetvdb', array('file' => 'class.thetvdb.php'));
		$tvdbapi = new Thetvdb('992BDB755BA8805D');
		
		// Fetch the episodes for the given series. It should allways be absolute_number
		/**
		 * The absolute numbering: 0 is always specials.
		 * 1 		= fetching episode 1
		 * 1 - 3 	= fetching episodes 1, 2, and 3
		 * 1 - 		= fetching all episodes from episode 1
		 * - 3 		= fetching episodes 1, 2, and 3
		 * NULL 	= fetching all seasons
		 *
		 */
		
		$episodesInfo = trim($item['ScrapeInfo']['scrape_episodes']);
		if(SCRAPEDEBUG)
			$this->out("\t".'Season information is set to: \'' . ($episodesInfo == NULL ? "NULL" : $episodesInfo) . '\'');
		
		// Fetching all the episodes
		if(SCRAPEDEBUG)
			$this->out("\t".'Fetching all episodes for series');
		$serie_info = $tvdbapi->GetSerieData($item['ScrapeInfo']['scrape_id'],true);
		
		$episodes = array();
		$start = 0;
		$end = count($serie_info['episodes']);

		// Filter out the ones we do not need
		if(strpos($episodesInfo,'-') !== FALSE)
		{
			$exploded = explode('-', $episodesInfo);
			if(!empty($exploded[0]))
				$start = $exploded[0];
			if(!empty($exploded[1]))
				$end = $exploded[1];
		}
		
		$latest_date = null;
		$beginning_date = null;
		foreach($serie_info['episodes'] as $episode)
		{
			if((int)$episode['absolute'] == $start)
				$beginning_date = $episode['airdate'];
			if((int)$episode['absolute'] == $end)
				$latest_date = $episode['airdate'];
		}

		foreach($serie_info['episodes'] as $episode)
		{
			$num = (int)$episode['absolute'];

			if($num !== 0 && ($num > $end || $num < $start))
				continue;

			// if the episode is a special
			if((int)$episode['season'] == 0 || $episode['season'] == '' || $episode['season'] == NULL)
			{	
				if(SCRAPEDEBUG)
					$this->out("\t" . "\t" . 'The episode is a special; endDate0:' . $latest_date . '; beginDate:'.$beginning_date);
				if($latest_date != null && strtotime($episode['airdate']) > strtotime($latest_date))
				{
					if(SCRAPEDEBUG)
						$this->out("\t"."\t".'Skipping Ep:\'' . $episode['absolute'] . '\': \'' . $episode['name'].'\'. The airdate is after the last episode');
					continue;
				}
				if($start != 0)	
					if($beginning_date != null && strtotime($episode['airdate']) < strtotime($beginning_date))
					{
						if(SCRAPEDEBUG)
							$this->out("\t"."\t".'Skipping Ep:\'' . $episode['absolute'] . '\': \'' . $episode['name'].'\'. The airdate is before the first episode');
						continue;
					}
			}
			
			// Add episode to db
			$special = $episode['season'] == 0 ? 1 :  NULL;
			
			// To make stuff simpler for now.. In episodes from thetvdb we skip the specials ;)
			if($start == 0)
				$episodeNumber = ((int)$episode['absolute']);
			else
				$episodeNumber = ((int)$episode['absolute'] - $start+1);
			if($special == NULL && $item['ScrapeInfo']['fetch_episodes'] == '1')
				$this->addEpisode( $item, $item['Anime']['id'], $episodeNumber, $episode['airdate'], $episode['name'], $episode['description'], $special );

		}
	}

	function addEpisode($scrapeInfo, $animeid, $number, $aired, $name, $description = '' , $special = NULL)
	{
		if($number < 0){
			if(SCRAPEDEBUG)
				$this->out("\t"."\t".'Episode :\'' . $number . '\': \'' . $name.'\' was not added because the episode number is negative');
			return false;
		}
			
		// We do not add episodes with no name from scraper.
		if(strlen($name) == 0)
		{
			if(SCRAPEDEBUG)
				$this->out("\t"."\t".'Episode :\'' . $number . '\': \'' . $name.'\' was not added because it has no name');
			return false;
		}
		if($special == NULL && (int)$number == 0)
		{
			if(SCRAPEDEBUG)
				$this->out("\t"."\t".'Episode :\'' . $number . '\': \'' . $name.'\' was not added because it has no number');
			return false;
		}
		if((string)$aired == '')
		{
			if(SCRAPEDEBUG)
				$this->out("\t"."\t".'Episode :\'' . $number . '\': \'' . $name.'\' was not added because it has no date');
			return false;
		}
		
		// Check if the exact episodes exists in the database
		$exact = $this->Episode->find('first',array('conditions' => array(
			'anime_id' 	=> $scrapeInfo['Anime']['id'],
			'special' 	=> $special,
			'number'	=> $number
		)));
		if(count($exact['Episode']) != 0){
			$added = false;
			// Allways prefer thetvdb episode descriptions.
			if((strlen($exact['Episode']['description']) == 0 && strlen($description) != 0) 
				|| ($scrapeInfo['ScrapeInfo']['scrape_source'] == 'thetvdb' && $description != $exact['Episode']['description'])){
				$this->Episode->read(NULL,$exact['Episode']['id']);
				$this->Episode->set('description',$description);
				$this->Episode->save();
				$this->stats['Episodes_changed']++;
				if(SCRAPEDEBUG)
					$this->out("\t"."\t".'Added missing description to episode :\'' . $number . '\': \'' . $name.'\' ..');
				$added = true;
			}
			// Sometimes anidb labels episodes with :Episode ## when it does not have a name.
			if((strpos(strtolower($exact['Episode']['name']),'episode') !== FALSE 
				&& strlen($exact['Episode']['name']) < 25 
				&& $exact['Episode']['name'] != $name) || ($exact['Episode']['name'] != $name && $scrapeInfo['ScrapeInfo']['scrape_source'] == 'anidb')){
				// Use the new name instead of old one.
				$this->Episode->read(NULL,$exact['Episode']['id']);
				$this->Episode->set('name',$name);
				$this->Episode->save();
				$this->stats['Episodes_changed']++;
				if(SCRAPEDEBUG)
					$this->out("\t"."\t".'Added missing name to episode :\'' . $number . '\': \'' . $name.'\' ..');
				$added = true;
			}
			// Check if date is different... Prefer dates from anidb over thetvdb
			if($aired != $exact['Episode']['aired'] && $scrapeInfo['ScrapeInfo']['scrape_source'] == 'anidb'){
				$this->Episode->read(NULL,$exact['Episode']['id']);
				$this->Episode->set('aired',$aired);
				$this->Episode->save();
				$this->stats['Episodes_changed']++;
				if(SCRAPEDEBUG)
					$this->out("\t"."\t".'Changed aired date on episode [anidb] :\'' . $number . '\': \'' . $name.'\' ..');
				$added = true;
			}
			if($added)
				return false;
			if(SCRAPEDEBUG)
				$this->out("\t"."\t".'Episode :\'' . $number . '\': \'' . $name.'\' already exists..');
			return false;
		}

		// Create the episode
		$this->Episode->create();
		$this->Episode->set('anime_id', $animeid);
		$this->Episode->set('special', $special);
		$this->Episode->set('number', $number);
		$this->Episode->set('name', $name);
		$this->Episode->set('aired', $aired);
		$this->Episode->set('description', $description);
		$this->stats['Episodes_added']++;
		$this->Episode->save();
		if(SCRAPEDEBUG)
			$this->out("\t"."\t".'Added :\'' . $number . '\': \'' . $name.'\'');
	}

}
?>
