<?php

require __DIR__.'/vendor/autoload.php';

use Curl\Curl;
use SimpleHtmlDom\simple_html_dom;

class Contributor {/*{{{*/
    public $name;
    public $github;
    public $email;
    public $location;

    public function __construct($name, $github, $email, $location) {/*{{{*/
        $this->name = $name;
        $this->github = $github;
        $this->email = $email;
        $this->location = $location;
    }/*}}}*/

    public function toString() {/*{{{*/
        return $this->name.'|'.$this->github.'|'.$this->email.'|'.$this->location;
    }/*}}}*/

}/*}}}*/

class Hunter {/*{{{*/

    const DEBUG = true;
    const RESTART = true;
    const CURL_TIMEOUT = 5;

    const GITHUB_INDEX = 'https://github.com';
    const SEARCH_URL = 'https://github.com/search?q=%s&p=%d';
    const CONTRIBUTORS_URL = 'https://github.com%s/graphs/contributors-data';

    private $tags = ['go'];
    private $dataDir;
    private $githubSession;
    private $githubGhsess;
    private $reposRoot;
    private $profileRoot;

    public function run() {/*{{{*/
        $this->init();

        foreach($this->tags as $tag) {
            $p = 1;
            while(true) {
                if ($p > 3) {
                    //break;
                }
                $reposUrl = sprintf(self::SEARCH_URL, $tag, $p++);
                $repos = $this->fetchRepos($reposUrl);
                if (!$repos) {
                    $this->info("repos empty, $reposUrl");
                    break;
                }
                foreach($repos as $repo) {
                    $contributorsUrl = sprintf(self::CONTRIBUTORS_URL, $repo);
                    $profileUrls = $this->fetchContributors($contributorsUrl);
                    if (!$profileUrls) {
                        $this->info("profileUrls empty, $contributorsUrl");
                        continue;
                    }
                    foreach($profileUrls as $url) {
                        $contributor = $this->fetchProfile($url);
                        if (!$contributor) {
                            $this->info("contributor empty, $url");
                            continue;
                        }
                        $this->save($tag, $contributor);
                    }
                }
            }
        }
    }/*}}}*/

    public function test() {/*{{{*/
        // test fetchProfile
        // var_dump($this->fetchProfile('https://github.com/Carbyn'));

        // test fetchContributors
        // var_dump($this->fetchContributors('https://github.com/avelino/awesome-go/graphs/contributors-data'));

        // test fetchRepos
        // var_dump($this->fetchRepos('https://github.com/search?p=2&q=go'));
    }/*}}}*/

    private function init() {/*{{{*/
        $this->dataDir = __DIR__.'/data';
        if (self::RESTART) {
            exec('rm -rf '.$this->dataDir);
        }
        if (!file_exists($this->dataDir)) {
            mkdir($this->dataDir);
        }
        $config = @json_decode(file_get_contents(__DIR__.'/config.json'), true);
        $this->githubSession = $config['GITHUB_SESSION'];
        $this->githubGhsess = $config['GITHUB_GHSESS'];
    }/*}}}*/

    private function fetchRepos($reposUrl) {/*{{{*/
        $html = $this->fetchUrl($reposUrl);
        if (!$html) {
            return null;
        }

        $dom = new simple_html_dom();
        $dom->load($html);
        $this->reposRoot = $dom->find('body', 0);
        if (!$this->reposRoot) {
            return null;
        }

        $repos = $this->getRepos();

        return $repos;
    }/*}}}*/

    private function fetchContributors($contributorsUrl) {/*{{{*/
        $data = $this->fetchUrl($contributorsUrl, true);
        if (!$data) {
            return null;
        }

        $data = json_decode($data, true);
        foreach($data as $d) {
            $profileUrls[] = self::GITHUB_INDEX.$d['author']['path'];
        }

        return $profileUrls;
    }/*}}}*/

    private function fetchProfile($profileUrl) {/*{{{*/
        $html = $this->fetchUrl($profileUrl);
        if (!$html) {
            return null;
        }

        $dom = new simple_html_dom();
        $dom->load($html);
        $this->profileRoot = $dom->find('body', 0);
        if (!$this->profileRoot) {
            return null;
        }

        $github = $profileUrl;
        $name = $this->getName();
        $email = $this->getEmail();
        $location = $this->getLocation();
        $contributor = new Contributor($name, $github, $email, $location);

        return $contributor;
    }/*}}}*/

    private function save($tag, $contributor) {/*{{{*/
        file_put_contents($this->dataDir.'/'.$tag.'.csv', $contributor->toString()."\n", FILE_APPEND);
    }/*}}}*/

    private function getRepos() {/*{{{*/
        $liNodes = $this->reposRoot->find('.repo-list-item');
        if (!$liNodes) {
            return null;
        }

        $repos = [];
        foreach($liNodes as $node) {
            $aNode = $node->find('.v-align-middle', 0);
            if (!$aNode) {
                continue;
            }
            $href = trim($aNode->getAttribute('href'));
            $repos[] = $href;
        }

        return $repos;
    }/*}}}*/

    private function getName() {/*{{{*/
        $node = $this->profileRoot->find('.vcard-fullname', 0);
        if (!$node) {
            return '';
        }
        $text = trim($node->innertext());
        return $text;
    }/*}}}*/

    private function getEmail() {/*{{{*/
        $node = $this->profileRoot->find('.u-email ', 0);
        if (!$node) {
            return '';
        }
        $text = html_entity_decode(trim($node->innertext()));
        return $text;
    }/*}}}*/

    private function getLocation() {/*{{{*/
        $node = $this->profileRoot->find('.vcard-details', 0);
        if (!$node) {
            return '';
        }
        $nodes = $node->find('li');
        if (!$nodes) {
            return '';
        }
        foreach($nodes as $n) {
            if ($n->getAttribute('itemprop') == 'homeLocation') {
                $spanNode = $n->find('span', 0);
                if (!$spanNode) {
                    return '';
                }
                return trim($spanNode->innertext());
            }
        }
        return '';
    }/*}}}*/

    private function fetchUrl($url, $isAjax = false, $retry = 3) {/*{{{*/
        $this->info("fetchUrl $url");
        $this->curl = new Curl();
        $this->curl->setopt(CURLOPT_TIMEOUT, self::CURL_TIMEOUT);
        $this->curl->setCookie('user_session', $this->githubSession);
        $this->curl->setCookie('_gh_sess', $this->githubGhsess);
        if ($isAjax) {
            $this->curl->setHeader('X-Requested-With', 'XMLHttpRequest');
        }

        $data = '';
        while ($retry-- > 0) {
            $this->curl->get($url);
            if (!$this->curl->error && $data = $this->curl->response) {
                break;
            }
        }

        if (!$data) {
            $this->info("fetchUrl failed: $url");
        }

        return $data;
    }/*}}}*/

    private function info($content) {/*{{{*/
        if (self::DEBUG) {
            if (is_array($content)) {
                print_r($content);
            } else {
                echo $content."\n";
            }
        }
    }/*}}}*/

}/*}}}*/

(new Hunter())->run();
