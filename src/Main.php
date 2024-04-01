<?php

// Jacob Schnettler
// MC Bedrock Home Plot Plugin

namespace jschnettler\HomeBoundaries;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\item\Item;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\math\Vector3;
use pocketmine\utils\TextFormat;

class Main extends PluginBase implements Listener
{
    private $plotPoints = [];

    private $allPlotPoints = [];

    private $playersInsidePlot = [];

    private function readCoordinates()
    {
        $filePath = $this->getDataFolder() . "coordinates.json";

        if (file_exists($filePath)) {
            $jsonString = file_get_contents($filePath);
            $this->allPlotPoints = json_decode($jsonString, true);

            $this->getLogger()->info("Plots loaded");
            $this->getLogger()->info(json_encode($this->allPlotPoints));
        }
    }

    private function generatePlotJSON(Player $player)
    {
        if (
            isset($this->plotPoints[$player->getName()]) &&
            count($this->plotPoints[$player->getName()]) === 2
        ) {
            $jsonArray = json_encode($this->plotPoints[$player->getName()]);
            $this->getLogger()->info(
                $player->getName() . "'s Plot JSON: $jsonArray"
            );
        }
    }

    private function saveCoordinates()
    {
        $filePath = $this->getDataFolder() . "coordinates.json";
        $jsonString = json_encode($this->allPlotPoints, JSON_PRETTY_PRINT);
        file_put_contents($filePath, $jsonString);
    }

    private function getPlayerPlotPoints(string $playerName): array
    {
        foreach ($this->allPlotPoints as $plotData) {
            if ($plotData["player"] === $playerName) {
                return $plotData["coordinates"];
            }
        }
        return [];
    }

    private function sendDiscordWebhook(string $webhookUrl, string $message)
    {
        $data = ["content" => $message];

        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        $result = curl_exec($ch);

        // Check for errors
        if ($result === false) {
            $this->getLogger()->warning(
                "Failed to send Discord webhook: " . curl_error($ch)
            );
        }

        curl_close($ch);
    }

    public function onEnable(): void
    {
        $this->getLogger()->info("HomeBoundaries has been enabled!");

        $this->readCoordinates();

        $this->getServer()
            ->getPluginManager()
            ->registerEvents($this, $this);
    }

    public function onCommand(
        CommandSender $sender,
        Command $command,
        string $label,
        array $args
    ): bool {
        switch ($command->getName()) {
            case "plot":
                if (empty($args)) {
                    // No arguments provided
                    $sender
                        ->getInventory()
                        ->addItem(
                            VanillaItems::WOODEN_AXE()->setCustomName(
                                "Plotting Axe"
                            )
                        );

                    $sender->sendMessage("You are now in plotting mode.");
                    $sender->sendMessage(
                        "Right click to select your first plot"
                    );
                    return true;
                } elseif ($args[0] === "cancel") {
                    // "cancel" argument
                    $player = $sender instanceof Player ? $sender : null;

                    if ($player !== null) {
                        unset($this->plotPoints[$player->getName()]);

                        $sender->sendMessage("Plot points cleared.");

                        return true;
                    } else {
                        $sender->sendMessage(
                            "This command can only be used in-game!"
                        );
                    }
                    return true;
                } elseif ($args[0] === "save") {
                    // "save" argument
                    if (!empty($this->plotPoints)) {
                        $plotData = [
                            "player" => $sender->getName(),
                            "coordinates" =>
                                $this->plotPoints[$sender->getName()], // Use the player's name as the key to access their plot points
                        ];
                        $this->allPlotPoints[] = $plotData;
                        $this->saveCoordinates();
                        $sender->sendMessage("Plot saved successfully!");
                    } else {
                        $sender->sendMessage("No plot points to save.");
                    }
                    return true;
                } else {
                    // Invalid argument provided
                    $sender->sendMessage("Usage: /plot [cancel|save]");
                    return false;
                }

                return true;
            default:
                throw new \AssertionError("This line will never be executed");
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event)
    {
        $player = $event->getPlayer();
        $item = $event->getItem();

        $this->getLogger()->info($item->getCustomName());
        // Check if the player is holding a wooden axe and right-clicked
        if (
            $item->hasCustomName() &&
            $item->getCustomName() === "Plotting Axe" &&
            $event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK
        ) {
            $position = $player->getPosition();
            $x = $position->getX();
            $y = $position->getY();
            $z = $position->getZ();

            // Store plot points for the current player
            if (!isset($this->plotPoints[$player->getName()])) {
                $this->plotPoints[$player->getName()] = [];
            }

            $playerPlotPoints = &$this->plotPoints[$player->getName()];

            if (count($playerPlotPoints) === 0) {
                $playerPlotPoints[] = ["x" => $x, "y" => $y, "z" => $z];
                $player->sendMessage(
                    "First plot point set at X: $x, Y: $y, Z: $z. Please select the second plot point."
                );
            } elseif (count($playerPlotPoints) === 1) {
                $playerPlotPoints[] = ["x" => $x, "y" => $y, "z" => $z];
                $player->sendMessage(
                    "Second plot point set at X: $x, Y: $y, Z: $z. Plot defined."
                );
                $this->generatePlotJSON($player);
            }
        }
    }

    public function onPlayerMove(PlayerMoveEvent $event)
    {
        $player = $event->getPlayer();
        $newPos = $event->getTo();
        $playerName = $player->getName();
        $isNewInsidePlot = false;

        foreach ($this->allPlotPoints as $plotData) {
            $plotPoints = $plotData["coordinates"];
            $plotMin = new Vector3(
                min($plotPoints[0]["x"], $plotPoints[1]["x"]),
                0,
                min($plotPoints[0]["z"], $plotPoints[1]["z"])
            );
            $plotMax = new Vector3(
                max($plotPoints[0]["x"], $plotPoints[1]["x"]),
                255,
                max($plotPoints[0]["z"], $plotPoints[1]["z"])
            );

            if (
                $newPos->getX() >= $plotMin->getX() &&
                $newPos->getX() <= $plotMax->getX() &&
                $newPos->getZ() >= $plotMin->getZ() &&
                $newPos->getZ() <= $plotMax->getZ()
            ) {
                // Check if the plot is owned by another player
                if ($plotData["player"] !== $playerName) {
                    $isNewInsidePlot = true;
                    break;
                }
            }
        }

        if ($isNewInsidePlot && !isset($this->playersInsidePlot[$playerName])) {
            // Player entered the plot area and is not already inside
            $this->playersInsidePlot[$playerName] = true;
            $webhookUrl =
                ""; // redacted
            $message =
                "**$playerName** has entered a plot owned by **" .
                $plotData["player"] .
                "**";

            $this->sendDiscordWebhook($webhookUrl, $message);

            $this->getServer()->broadcastMessage(
                "$playerName has entered a plot owned by " . $plotData["player"]
            );
        } elseif (
            !$isNewInsidePlot &&
            isset($this->playersInsidePlot[$playerName])
        ) {
            // Player exited the plot area and was previously inside
            unset($this->playersInsidePlot[$playerName]);
        }
    }
}
