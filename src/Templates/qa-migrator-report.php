<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prelaunch Site QA Report</title>
</head>
<body>
    <h1>Prelaunch Site QA</h1>
    <p>Commands ran:</p>
    <ul>
        <?php foreach ( $logs as $command => $logs ) : ?>
            <li><?= $command; ?></li>
        <?php endforeach; ?>
    </ul>
    
    <h1>Logs from commands</h1>

    <?php foreach ( $logs as $command => $logs ) : ?>
        
        <h2><?= $command; ?></h2>
        <div class="accordion">
            <?= $logs; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>