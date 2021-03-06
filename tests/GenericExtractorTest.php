<?php

namespace Keboola\GenericExtractor\Tests;

use Keboola\GenericExtractor\Configuration\Api;
use Keboola\GenericExtractor\GenericExtractor;
use Keboola\Juicer\Config\Config;
use Keboola\Juicer\Exception\UserException;
use Keboola\Juicer\Parser\Json;
use Keboola\Temp\Temp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GenericExtractorTest extends TestCase
{
    /**
     * No change to JSON parser structure should happen when nothing is parsed!
     */
    public function testRunMetadataNoUpdate()
    {
        $meta = [
            'json_parser.struct' => [
                'tickets.via' => ['channel' => 'scalar', 'source' => 'object']
            ],
            'time' => [
                'previousStart' => 123
            ]
        ];

        $cfg = new Config(['jobs' => [['endpoint' => 'get']]]);
        $api = new Api(new NullLogger(), ['baseUrl' => 'http://example.com/'], [], []);
        $ex = new GenericExtractor(new Temp(), new NullLogger(), $api);

        $ex->setMetadata($meta);
        try {
            $ex->run($cfg);
        } catch (UserException $e) {
        }
        $after = $ex->getMetadata();

        self::assertEquals($meta['json_parser.struct'], $after['json_parser.struct']);
        self::assertArrayHasKey('time', $after);
    }

    public function testRunMetadataUpdate()
    {
        $meta = [
            'json_parser.struct' => [
                'tickets.via' => ['channel' => 'scalar', 'source' => 'object']
            ],
            'time' => [
                'previousStart' => 123
            ]
        ];

        $cfg = new Config(['jobs' => [['endpoint' => 'get']]]);
        $api = new Api(new NullLogger(), ['baseUrl' => 'http://private-74f7c-extractormock.apiary-mock.com/'], [], []);
        $ex = new GenericExtractor(new Temp(), new NullLogger(), $api);

        $ex->setMetadata($meta);
        $ex->run($cfg);
        $after = $ex->getMetadata();

        $meta['json_parser.struct']['get'] = ['id' => 'scalar', 'status' => 'scalar'];
        self::assertEquals($meta['json_parser.struct'], $after['json_parser.struct']);
        self::assertArrayHasKey('time', $after);
    }

    public function testGetParser()
    {
        $temp = new Temp();
        $parser = new Json(new NullLogger(), $temp);
        $api = new Api(new NullLogger(), ['baseUrl' => 'http://example.com'], [], []);
        $extractor = new GenericExtractor($temp, new NullLogger(), $api);
        $extractor->setParser($parser);
        self::assertEquals($parser, $extractor->getParser());
    }
}
