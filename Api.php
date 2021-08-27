<?php

/** @noinspection PhpUnhandledExceptionInspection */
/** @noinspection PhpUndefinedMethodInspection */
/** @noinspection PhpUnusedParameterInspection */

declare(strict_types=1);

namespace Zaplog {

    define("BASE_PATH", dirname(__FILE__));

    require_once BASE_PATH . '/vendor/autoload.php';
    require_once BASE_PATH . '/Middleware/Authentication.php';
    require_once BASE_PATH . '/Library/HtmlMetadata.php';
    require_once BASE_PATH . '/Library/FeedReader.php';
    require_once BASE_PATH . '/Library/TwoFactorAuth.php';
    require_once BASE_PATH . '/Exception/ResourceNotFoundException.php';
    require_once BASE_PATH . '/Exception/EmailException.php';

//    use SlimRestApi\Middleware\CliRequest;
    use SlimRestApi\Middleware\Memcaching;
    use stdClass;
    use Exception;
    use SlimRequestParams\BodyParameters;
    use SlimRequestParams\QueryParameters;
    use SlimRestApi\Middleware\ReadOnly;
    use SlimRestApi\SlimRestApi;
    use Psr\Http\Message\ResponseInterface;
    use Psr\Http\Message\ServerRequestInterface;
    use SlimRestApi\Infra\Db;
    use Zaplog\Exception\EmailException;
    use Zaplog\Exception\ResourceNotFoundException;
    use Zaplog\Library\FeedReader;
    use Zaplog\Library\HtmlMetadata;
    use Zaplog\Library\TwoFactorAuth;
    use Zaplog\Middleware\Authentication;

    class Api extends SlimRestApi
    {
        /** @noinspection PhpUndefinedFieldInspection */
        public function __construct()
        {
            parent::__construct();

            // -----------------------------------------
            // show the API homepage
            // -----------------------------------------

            $this->get("/", function ($rq, $rp, $args): ResponseInterface {
                echo "<h1>ZAPLOG REST-API</h1>";
                echo "<p>See: <a href='https://github.com/zaplogv2/api.zaplog'>Github repository</a></p>";
                echo "<table>";
                foreach ($this->router->getRoutes() as $route) {
                    foreach ($route->getMethods() as $method) {
                        echo "<tr><td>$method</td><td>{$route->getPattern()}</td></tr>";
                    }
                }
                echo "</table>";
                return $rp;
            });

            // -----------------------------------------------------
            // The 2FA hook, if you have a token, you can execute
            // the triggers associated with the token
            // -----------------------------------------------------

            $this->get("/2factor/{utoken:[[:alnum:]]{32}}", new TwoFactorAuth);

            // -----------------------------------------------------
            // send a single-use auto-expiring log-in link to email
            // uses an email template /login.html
            // -----------------------------------------------------

            $this->post("/sessions/{emailencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $email = urldecode($args->emailencoded);
                $args->receiver = $email;
                $Auth = new TwoFactorAuth;
                try {
                    $Auth
                        ->addTrigger('Middleware/Authentication.php', ['\Zaplog\Middleware\Authentication', 'createSession'], [$email])
                        ->createToken()
                        ->sendToken($args);
                    return $response->withJson(null);
                } catch (EmailException $e) {
                    // TODO remove in production
                    return $response->withJson($Auth->utoken);
                }
            })
                ->add(new BodyParameters([
                    '{subject:.{1,128}},Your single-use login link',
                    '{button:.{1,30}},Login',
                    '{button_url:\url},null',
                    '{template_url:\url},login.html',
                    '{*}',
                ]));

            // -----------------------------------------------------
            // invalidate the session token
            // -----------------------------------------------------

            $this->delete("/sessions", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                Db::execute("DELETE FROM sessions WHERE token=:token", [":token" => Authentication::token()]);
                return $response->withJson(null);
            })
                ->add(new Authentication);

            // ----------------------------------------------------------------
            // Return the active channels (sessions) 'who's online'
            // ----------------------------------------------------------------

            $this->get("/sessions", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM whosonline")->fetchAll());
            })
                ->add(new ReadOnly);

            // ----------------------------------------------------------------
            // Return channels (index, top, new)
            // ----------------------------------------------------------------

            $this->get("/channels/id/{id:[\d]{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $channel = Db::execute("SELECT * FROM channels WHERE id=:id", [":id" => $args->id])->fetchAll();
                // select the most popular tags of this channel
                $tags = Db::execute("SELECT tag, COUNT(tag) AS tagscount 
                    FROM tags 
                    JOIN links ON tags.linkid=links.id 
                    WHERE links.channelid=:channelid 
                    AND tags.channelid=links.channelid
                    GROUP BY tag ORDER BY COUNT(tag) DESC LIMIT 10",
                    [":channelid" => $args->id])->fetchAll();
                $related = Db::execute("SELECT 1", [])->fetchAll();
                return $response->withJson(
                    [
                        "channel" => $channel,
                        "tags" => $tags,
                        "related" => $related,
                    ]
                );
            })
                ->add(new Memcaching(60/*sec*/))
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{offset:\int},0',
                    '{count:\int},20',
                ]));

            $this->get("/channels/index", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM channels ORDER BY name LIMIT :offset,:count",
                    [
                        ":offset" => $args->offset,
                        ":count" => $args->count,
                    ]
                )->fetchAll());
            })
                ->add(new Memcaching(60/*sec*/))
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{offset:\int},0',
                    '{count:\int},20',
                ]));

            $this->get("/channels/top", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM channels ORDER BY reputation DESC LIMIT 25")->fetchAll());
            })
                ->add(new Memcaching(3600/*sec*/))
                ->add(new ReadOnly);

            $this->get("/channels/new", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM channels ORDER BY id DESC LIMIT 25")->fetchAll());
            })
                ->add(new Memcaching(10/*sec*/))
                ->add(new ReadOnly);

            // ----------------------------------------------------------------
            // Return a link, including tags and related links
            // ----------------------------------------------------------------

            $this->get("/links/id/{id:\d{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $params = [":id" => $args->id];
                $link = Db::execute("SELECT * FROM links WHERE id=:id", $params)->fetch();
                if (!$link) throw new ResourceNotFoundException;
                $tags = Db::execute("SELECT * FROM tags WHERE linkid=:id", $params)->fetchAll();
                $rels = Db::execute("SELECT links.*, GROUP_CONCAT(tags.tag SEPARATOR ',') AS tags 
                    FROM links JOIN tags 
                        ON tags.linkid=links.id 
                        AND links.id<>:id1
                        AND tag IN 
                            (
                                SELECT tags.tag FROM links LEFT JOIN tags on tags.linkid=links.id WHERE links.id=:id2 
                            )
                        GROUP BY links.id
                        ORDER BY COUNT(links.id) DESC
                        LIMIT 5",
                    [
                        ":id1" => $args->id,
                        ":id2" => $args->id,
                    ])->fetchAll();
                Db::execute("UPDATE links SET viewscount = viewscount + 1 WHERE id=:id", $params);
                return $response->withJson(
                    [
                        "link" => $link,
                        "tags" => $tags,
                        "related" => $rels,
                    ]
                );
            });

            // -----------------------------------------------------
            // Returns the currently selected frontpage links
            // Always exactly 20 items
            // -----------------------------------------------------

            $this->get("/links/frontpage", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM frontpage")->fetchAll());
            })
                ->add(new Memcaching(60/*sec*/))
                ->add(new ReadOnly);

            // -----------------------------------------------------
            // Returns the top scoring links for a given tag
            // -----------------------------------------------------

            $this->get("/links/tag/{tag:[\w-]{3,55}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $links = Db::execute("SELECT links.* FROM tags
                    LEFT JOIN links ON tags.linkid=links.id 
                    WHERE tags.tag=:tag
                    GROUP BY links.id 
                    ORDER BY links.score, links.createdatetime DESC 
                    LIMIT :offset,:count",
                    [
                        ":tag" => $args->tag,
                        ":offset" => $args->offset,
                        ":count" => $args->count,
                    ])->fetchAll();
                return $response->withJson($links);
            })
                ->add(new Memcaching(60/*sec*/))
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{offset:\int},0',
                    '{count:\int},20',
                ]));

            // -----------------------------------------------------
            // Returns links
            // -----------------------------------------------------

            $this->get("/links", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $links = Db::execute("SELECT * FROM links 
                    WHERE (:channel1 IS NULL OR channelid = :channel2)
                    ORDER BY :order DESC LIMIT :offset,:count",
                    [
                        ":channel1" => $args->channel,
                        ":channel2" => $args->channel,
                        ":offset" => $args->offset,
                        ":count" => $args->count,
                        ":order" => $args->order,
                    ])->fetchAll();
                return $response->withJson($links);
            })
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{search:.+},null',
                    '{channel:\int},null',
                    '{offset:\int},0',
                    '{count:\int},20',
                    '{order:(id|score)},id',
                ]));

            // ----------------------------------------------------
            // return the metadata of a HTML page
            // ----------------------------------------------------

            $this->get("/links/metadata/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson((new HtmlMetadata)(urldecode($args->urlencoded)));
            })
                ->add(new Authentication);

            // ----------------------------------------------------
            // add a link, retrieve its metadata
            // ----------------------------------------------------

            $this->post("/links/{urlencoded:(?:[^%]|%[0-9A-Fa-f]{2})+}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $metadata = (new HtmlMetadata)(urldecode($args->urlencoded));
                if (Db::execute("INSERT INTO links(url,channelid,title,description,image)
                VALUES (:url, :channelid, :title, :description, :image)",
                        [
                            ":url" => $metadata["url"],
                            ":channelid" => Authentication::token()->channelid,
                            ":title" => $metadata["title"],
                            ":description" => $metadata["description"],
                            ":image" => $metadata["image"],
                        ])->rowCount() == 0
                ) {
                    throw new Exception;
                }
                $linkid = Db::lastInsertId();
                foreach ($metadata['keywords'] as $tag) {
                    // these metadata tags are not assigned to a channel
                    Db::execute("INSERT INTO tags(linkid, tag) VALUES (:linkid, :tag)",
                        [
                            ":linkid" => $linkid,
                            ":tag" => $tag,
                        ]);
                }
                return $response->withJson($linkid);
            })
                ->add(new Authentication);

            // --------------------------------------------------
            // delete a link by it's id
            // --------------------------------------------------

            $this->delete("/links/{id:\d{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("DELETE FROM links WHERE id =:id", [":id" => $args->id])->rowCount() == 0)
                    throw new ResourceNotFoundException;
                return $response->withJson(null);
            })
                ->add(new Authentication);

            // ------------------------------------------------
            // up vote a link
            // ------------------------------------------------

            $this->post("/votes/{id:\d{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("INSERT INTO votes(linkid, channelid) VALUES(:id, :channelid)",
                        [
                            ":id" => $args->id,
                            ":channelid" => Authentication::token()->channelid,
                        ])->rowCount() == 0
                ) {
                    throw new Exception;
                }
                return $response->withJson(Db::lastInsertId());
            })
                ->add(new Authentication);

            // ------------------------------------------------
            // post a single tag POST /tags/{id}/{tag}
            // ------------------------------------------------

            $this->post("/tags/{id:\d{1,10}}/{tag:[\w-]{3,50}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("INSERT INTO tags(linkid, channelid, tag) VALUES(:id, :channelid, :tag)",
                        [
                            ":id" => $args->id,
                            ":tag" => $args->tag,
                            ":channelid" => Authentication::token()->channelid,
                        ])->rowCount() == 0
                ) {
                    throw new Exception;
                }
                return $response->withJson(Db::lastInsertId());
            })
                ->add(new Authentication);

            // ------------------------------------------------
            // delete a tag, only delete your own tags
            // ------------------------------------------------

            $this->delete("/tags/{id:\d{1,10}}", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                if (Db::execute("DELETE tags FROM tags WHERE id=:id and channelid=:channelid",
                        [
                            ":id" => $args->id,
                            ":channelid" => Authentication::token()->channelid,
                        ])->rowCount() == 0
                ) {
                    throw new Exception;
                }
                return $response->withJson(null);
            })
                ->add(new Authentication);

            // ------------------------------------------------
            // return all tags unique sorted
            // ------------------------------------------------

            $this->get("/tags/index", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(
                    Db::execute("SELECT tag, COUNT(tag) as linkscount FROM tags GROUP BY tag ORDER BY tag", [])->fetchAll()
                );
            })
                ->add(new Memcaching(10/*sec*/))
                ->add(new ReadOnly);

            // ------------------------------------------------
            // get the top trending tags
            // ------------------------------------------------

            $this->get("/tags/trending", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM trendingtopics")->fetchAll());
            })
                ->add(new Memcaching(10/*sec*/))
                ->add(new ReadOnly);

            // ------------------------------------------------
            // get the activity stream
            // ------------------------------------------------

            $this->get("/activities", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                $activities = Db::execute("SELECT * FROM activitystream ORDER BY id DESC LIMIT :offset,:count",
                    [
                        ":offset" => $args->offset,
                        ":count" => $args->count,
                    ])->fetchAll();
                return $response->withJson($activities);
            })
                ->add(new Memcaching(10/*sec*/))
                ->add(new ReadOnly)
                ->add(new QueryParameters([
                    '{channel:[\w-]{3,54}},null',
                    '{link:,\d{1,10}},null',
                    '{offset:\int},0',
                    '{count:\int},20',
                ]));

            // ------------------------------------------------
            // get some basic statistics
            // ------------------------------------------------

            $this->get("/statistics", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                return $response->withJson(Db::execute("SELECT * FROM statistics")->fetch());
            })
                ->add(new Memcaching(10/*sec*/))
                ->add(new ReadOnly);

            // ------------------------------------------------
            // generic cronjob interfaces, not public
            // ------------------------------------------------

            $this->get("/cronjobhour", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                set_time_limit(300/*5min*/);
                (new FeedReader)();
                return $response;
            });
//                ->add(new CliRequest);

            $this->get("/cronjobday", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                set_time_limit(300/*5min*/);
                return $response;
            });
//                ->add(new CliRequest);

            $this->get("/cronjobmonth", function (
                ServerRequestInterface $request,
                ResponseInterface      $response,
                stdClass               $args): ResponseInterface {
                set_time_limit(300/*5min*/);
                return $response;
            });
//                ->add(new CliRequest);

        }
    }

    // -------------------------------------------------
    // Execute the server
    // -------------------------------------------------

    (new Api)->run();
}