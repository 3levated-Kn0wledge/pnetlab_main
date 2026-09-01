<?php
namespace App\Helpers\Captcha;

class Captcha {

    /**
     * Fonts this captcha will draw with, most preferred first.
     *
     * WHY THIS IS A LIST AND NOT A CONSTANT
     *
     * It used to be one file: ARIALBD.TTF, sitting next to this class. That
     * font is Arial Bold — "(c) 2014 The Monotype Corporation. All Rights
     * Reserved" is embedded in its name table — and it was being redistributed
     * in a public source repository with no licence permitting it. It arrived
     * with the upstream import; nobody chose it. It is gone, and it must not
     * come back. See docs/LICENSING.md section 3.
     *
     * The replacements are all freely redistributable:
     *
     *   - DejaVu Sans Bold ships in fonts-dejavu-core, which is on every
     *     Ubuntu image this project targets (Bitstream Vera licence plus the
     *     DejaVu public-domain amendment).
     *   - Liberation Sans Bold is the metric-compatible Arial substitute
     *     (SIL OFL 1.1), for hosts that carry that set instead.
     *   - Ubuntu Bold is the guaranteed one. It is IN THIS REPOSITORY, under
     *     the Ubuntu Font Licence 1.0, with the licence text beside it at
     *     themes/default/fonts/LICENCE.txt. The installer rsyncs the
     *     repository root, so it is present on every deployed appliance and
     *     the captcha works with no font package installed and no network.
     *     That is the offline-first property. Do not remove the last entry.
     *
     * Relative entries resolve against this directory. Four levels up from
     * store/app/Helpers/Captcha is the web root.
     */
    public static $FONTS = [
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf',
        '../../../../themes/default/fonts/Ubuntu-B.ttf',
    ];

    public static $CONFIG = array(
        'CAPTCHA_POOL' => 'ABCDEFGHJKLMNPQRSTUVWXYZ1234567890',
        'CAPTCHA_LENG' => 2,
        'CAPTCHA_SIZE' => 20,
        'CAPTCHA_WIDTH' => 80,
        'CAPTCHA_HEIGHT' => 40,
        'CAPTCHA_BACKGROUND'=>[255,255,255],
        'CAPTCHA_COLOR' => [64,64,64],
        );

    /**
     * The first font in self::$FONTS that is present and readable, or null.
     *
     * Absolute entries are taken as given; relative ones resolve against this
     * file's directory, so the class stays movable with the tree.
     */
    public static function fontPath(){
        foreach(self::$FONTS as $candidate){
            $path = ($candidate !== '' && $candidate[0] === '/')
                ? $candidate
                : __DIR__ . '/' . $candidate;
            if(is_file($path) && is_readable($path)) return $path;
        }
        return null;
    }

    public static function createCaptcha($id){
        $font = self::fontPath();
        if($font === null){
            // Every candidate missing means the themes tree did not deploy.
            // Log it: a blank image and a login that cannot succeed is the
            // worst way to find that out.
            error_log('Captcha: no usable font found; tried ' . implode(', ', self::$FONTS));
            return ['img' => ''];
        }

        $captcha_num = substr(str_shuffle(self::$CONFIG['CAPTCHA_POOL']), 0, self::$CONFIG['CAPTCHA_LENG']);
        $image = imagecreate(self::$CONFIG['CAPTCHA_WIDTH'], self::$CONFIG['CAPTCHA_HEIGHT']);
        $backgroundColor = imagecolorallocate($image, ...self::$CONFIG['CAPTCHA_BACKGROUND']); // set background color
        $captcharColor = imagecolorallocate($image, ...self::$CONFIG['CAPTCHA_COLOR']); // set text color
        imagettftext($image, self::$CONFIG['CAPTCHA_SIZE'], rand()%15, 5, self::$CONFIG['CAPTCHA_HEIGHT']-5, $captcharColor, $font, $captcha_num);

        $line_color = imagecolorallocate($image, 64,64,64);
        for($i=0; $i<5; $i++) {
            imageline($image,0,rand()%self::$CONFIG['CAPTCHA_HEIGHT'], self::$CONFIG['CAPTCHA_WIDTH'] ,rand()%self::$CONFIG['CAPTCHA_HEIGHT'] , $line_color);
        }

        $pixel_color = imagecolorallocate($image, 0,0,255);
        for($i=0; $i<500; $i++) {
            imagesetpixel($image,rand()%self::$CONFIG['CAPTCHA_WIDTH'], rand()%self::$CONFIG['CAPTCHA_HEIGHT'], $pixel_color);
        }

        ob_start();
        imagejpeg($image);
        $imagedata = ob_get_clean();
        $imgHtml = '<img src="data:image/png;base64,'.base64_encode($imagedata).'" alt="captchar" width="'.self::$CONFIG['CAPTCHA_WIDTH'].'" height="'.self::$CONFIG['CAPTCHA_HEIGHT'].'"/>';
        session(['captcha_'.$id=> $captcha_num]);
        return ['img' => $imgHtml];
    }

    public static function verifyCaptcha($captcha){
        if(!is_array($captcha)) return false;
        $id = array_keys($captcha)[0];
        if(session('captcha_'.$id) == '' || session('captcha_'.$id) == null) return false;
        if($captcha[$id] == session('captcha_'.$id)){
            session(['captcha_'.$id=>'']);
            return true;
        }else{
            session(['captcha_'.$id=>'']);
        }

    }

}
