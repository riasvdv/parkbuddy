<?php
use BotMan\BotMan\BotMan;
use BotMan\BotMan\Messages\Attachments\Location;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use Spatie\Geocoder\Facades\Geocoder;

/** @var BotMan $botman */
$botman = resolve('botman');

$botman->hears('(Hi|Hello|Hallo|Hoi)!?', function (BotMan $bot) {
    return $bot->reply('Hey! Stuur me een adres of locatie, en ik hou je op de hoogte of er een parkeerverbod komt.');
});

$botman->hears('(Stop|Clear|Bye|Verwijder|Weg)!?', function (BotMan $bot) {
    $bot->userStorage()->delete();

    return $bot->reply('Je locatie is verwijderd. Drive safe!');
});

$botman->receivesLocation(function (BotMan $bot, Location $location) {
    $lat = $location->getLatitude();
    $lng = $location->getLongitude();
    $address = Geocoder::getAddressForCoordinates($lat, $lng);

    $bot->userStorage()->save([
        'user' => [
            'id' => $bot->getUser()->getId(),
            'firstname' => $bot->getUser()->getFirstName(),
        ],
        'address' => $address['formatted_address'],
        'lat' => $lat,
        'lng' => $lng,
        'driver' => $bot->getDriver()->getName(),
        'notified' => [],
    ], $bot->getUser()->getId());

    return $bot->reply("Bedankt {$bot->getUser()->getFirstName()}! Ik hou je op de hoogte als er een parkeerverbod op die locatie komt.");
});

$botman->hears('(.*)', function (BotMan $bot, $message) {
    $coordinates = Geocoder::getCoordinatesForAddress($message);

    if ($coordinates['lat'] === 0 && $coordinates['lng'] === 0) {
        return $bot->reply("Sorry, dit adres kon ik niet vinden.");
    }

    $bot->userStorage()->save([
        'user' => [
            'id' => $bot->getUser()->getId(),
            'firstname' => $bot->getUser()->getFirstName(),
        ],
        'address' => $message,
        'lat' => $coordinates['lat'],
        'lng' => $coordinates['lng'],
        'driver' => $bot->getDriver()->getName(),
        'notified' => [],
    ], $bot->getUser()->getId());

    if ($bot->getDriver()->getName() === 'Telegram') {
        $attachment = new Location($coordinates['lat'], $coordinates['lng']);
        $message = OutgoingMessage::create()
            ->withAttachment($attachment);
        $bot->reply($message);
    }

    return $bot->reply("Bedankt {$bot->getUser()->getFirstName()}! Ik hou je op de hoogte als er een parkeerverbod in de buurt van die locatie komt.");
});