<?php

namespace App\Console\Commands;

use BotMan\BotMan\Facades\BotMan;
use BotMan\Drivers\Telegram\TelegramDriver;
use GuzzleHttp\Client;
use GuzzleHttp\Pool;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Location\Coordinate;
use Location\Distance\Vincenty;
use Location\Polygon;
use Location\Polyline;

class NotifyParkingProhibitions extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'parkbuddy:notify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify all users of parking prohibitions.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $client = new Client();
        $userInformation = BotMan::userStorage()->all();
        $requests = [];
        for ($now = Carbon::now(); $now <= now()->addDays(7); $now->addDay()) {
            $requests[] = new Request('GET', 'https://parkeerverbod.info/features/parkingBanIntake?from_date='.$now->format('Y-m-d'));
        }

        $responses = Pool::batch($client, $requests, ['concurrency' => 100]);

        $prohibitions = collect();
        /** @var Response $response */
        foreach ($responses as $response) {
            $response = json_decode($response->getBody()->getContents(), true);
            foreach ($response['features'] as $feature) {
                $prohibitions->push($feature);
            }
        }

        $prohibitions = $prohibitions->unique();
        $calculator = new Vincenty();
        foreach ($userInformation as $userInfo) {
            $parkingLocation = new Coordinate($userInfo['lat'], $userInfo['lng']);

            foreach ($prohibitions as $prohibition) {
                if ($prohibition['geometry']['type'] === "LineString") {
                    $prohibition1 = new Coordinate($prohibition['geometry']['coordinates'][0][1], $prohibition['geometry']['coordinates'][0][0]);
                    $prohibition2 = new Coordinate($prohibition['geometry']['coordinates'][1][1], $prohibition['geometry']['coordinates'][1][0]);

                    $distances = array_filter([
                        $calculator->getDistance($prohibition1, $parkingLocation),
                        $calculator->getDistance($prohibition2, $parkingLocation),
                    ]);

                    $averageDistance = array_sum($distances) / count($distances);
                } elseif ($prohibition['geometry']['type'] === "Polygon") {
                    $polygon = new Polygon();
                    foreach ($prohibition['geometry']['coordinates'][0] as $coordinate) {
                        $polygon->addPoint(new Coordinate($coordinate[1], $coordinate[0]));
                    }

                    if ($polygon->contains($parkingLocation)) {
                        $averageDistance = 0;
                    } else {
                        $averageDistance = 1000;
                    }
                } else {
                    $averageDistance = 1000;
                }

                if ($averageDistance <= 50 && !in_array($prohibition['properties']['locationId'], $userInfo['notified'])) {
                    $start = Carbon::parse($prohibition['properties']['dateFrom']);
                    $end = Carbon::parse($prohibition['properties']['dateUntil']);
                    $timeStart = $prohibition['properties']['timeFrom'];
                    $timeEnd = $prohibition['properties']['timeUntil'];
                    $url = $prohibition['properties']['url'];

                    BotMan::say(
                        "Hoi {$userInfo['user']['firstname']}, er is een parkeerverbod bij {$prohibition['properties']['address']} gepland van *{$start->format('d/m')}* t.e.m. *{$end->format('d/m')}* tussen {$timeStart} en {$timeEnd}. Meer info? {$url}",
                        $userInfo['user']['id'],
                        $userInfo['driver'],
                        [
                            'parse_mode' => 'markdown'
                        ]
                    );

                    $storage = BotMan::userStorage()->find($userInfo['user']['id'])->toArray();
                    $storage['notified'][] = $prohibition['properties']['locationId'];
                    BotMan::userStorage()->save($storage, $userInfo['user']['id']);
                }
            }
        }
    }
}
