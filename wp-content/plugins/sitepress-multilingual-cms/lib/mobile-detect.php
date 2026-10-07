<?php

class WPML_Mobile_Detect {

    protected $scriptVersion = '2.5.7';

    protected $userAgent = null;
    protected $httpHeaders;

    protected $mobileDetectionRules = null;
    protected $mobileDetectionRulesExtended = null;
    protected $detectionType = 'mobile';

    protected $phoneDevices = array(
        'iPhone'        => '\biPhone.*Mobile|\biPod|\biTunes',
        'BlackBerry'    => 'BlackBerry|\bBB10\b|rim[0-9]+',
        'HTC'           => 'HTC|HTC.*(Sensation|Evo|Vision|Explorer|6800|8100|8900|A7272|S510e|C110e|Legend|Desire|T8282)|APX515CKT|Qtek9090|APA9292KT|HD_mini|Sensation.*Z710e|PG86100|Z715e|Desire.*(A8181|HD)|ADR6200|ADR6425|001HT|Inspire 4G',
        'Nexus'         => 'Nexus One|Nexus S|Galaxy.*Nexus|Android.*Nexus.*Mobile',
        'Dell'          => 'Dell.*Streak|Dell.*Aero|Dell.*Venue|DELL.*Venue Pro|Dell Flash|Dell Smoke|Dell Mini 3iX|XCD28|XCD35|\b001DL\b|\b101DL\b|\bGS01\b',
        'Motorola'      => 'Motorola|\bDroid\b.*Build|DROIDX|Android.*Xoom|HRI39|MOT-|A1260|A1680|A555|A853|A855|A953|A955|A956|Motorola.*ELECTRIFY|Motorola.*i1|i867|i940|MB200|MB300|MB501|MB502|MB508|MB511|MB520|MB525|MB526|MB611|MB612|MB632|MB810|MB855|MB860|MB861|MB865|MB870|ME501|ME502|ME511|ME525|ME600|ME632|ME722|ME811|ME860|ME863|ME865|MT620|MT710|MT716|MT720|MT810|MT870|MT917|Motorola.*TITANIUM|WX435|WX445|XT300|XT301|XT311|XT316|XT317|XT319|XT320|XT390|XT502|XT530|XT531|XT532|XT535|XT603|XT610|XT611|XT615|XT681|XT701|XT702|XT711|XT720|XT800|XT806|XT860|XT862|XT875|XT882|XT883|XT894|XT909|XT910|XT912|XT928',
        'Samsung'       => 'Samsung|BGT-S5230|GT-B2100|GT-B2700|GT-B2710|GT-B3210|GT-B3310|GT-B3410|GT-B3730|GT-B3740|GT-B5510|GT-B5512|GT-B5722|GT-B6520|GT-B7300|GT-B7320|GT-B7330|GT-B7350|GT-B7510|GT-B7722|GT-B7800|GT-C3010|GT-C3011|GT-C3060|GT-C3200|GT-C3212|GT-C3212I|GT-C3222|GT-C3300|GT-C3300K|GT-C3303|GT-C3303K|GT-C3310|GT-C3322|GT-C3330|GT-C3350|GT-C3500|GT-C3510|GT-C3530|GT-C3630|GT-C3780|GT-C5010|GT-C5212|GT-C6620|GT-C6625|GT-C6712|GT-E1050|GT-E1070|GT-E1075|GT-E1080|GT-E1081|GT-E1085|GT-E1087|GT-E1100|GT-E1107|GT-E1110|GT-E1120|GT-E1125|GT-E1130|GT-E1160|GT-E1170|GT-E1175|GT-E1180|GT-E1182|GT-E1200|GT-E1210|GT-E1225|GT-E1230|GT-E1390|GT-E2100|GT-E2120|GT-E2121|GT-E2152|GT-E2220|GT-E2222|GT-E2230|GT-E2232|GT-E2250|GT-E2370|GT-E2550|GT-E2652|GT-E3210|GT-E3213|GT-I5500|GT-I5503|GT-I5700|GT-I5800|GT-I5801|GT-I6410|GT-I6420|GT-I7110|GT-I7410|GT-I7500|GT-I8000|GT-I8150|GT-I8160|GT-I8320|GT-I8330|GT-I8350|GT-I8530|GT-I8700|GT-I8703|GT-I8910|GT-I9000|GT-I9001|GT-I9003|GT-I9010|GT-I9020|GT-I9023|GT-I9070|GT-I9100|GT-I9103|GT-I9220|GT-I9250|GT-I9300|GT-I9300 |GT-M3510|GT-M5650|GT-M7500|GT-M7600|GT-M7603|GT-M8800|GT-M8910|GT-N7000|GT-P6810|GT-P7100|GT-S3110|GT-S3310|GT-S3350|GT-S3353|GT-S3370|GT-S3650|GT-S3653|GT-S3770|GT-S3850|GT-S5210|GT-S5220|GT-S5229|GT-S5230|GT-S5233|GT-S5250|GT-S5253|GT-S5260|GT-S5263|GT-S5270|GT-S5300|GT-S5330|GT-S5350|GT-S5360|GT-S5363|GT-S5369|GT-S5380|GT-S5380D|GT-S5560|GT-S5570|GT-S5600|GT-S5603|GT-S5610|GT-S5620|GT-S5660|GT-S5670|GT-S5690|GT-S5750|GT-S5780|GT-S5830|GT-S5839|GT-S6102|GT-S6500|GT-S7070|GT-S7200|GT-S7220|GT-S7230|GT-S7233|GT-S7250|GT-S7500|GT-S7530|GT-S7550|GT-S7562|GT-S8000|GT-S8003|GT-S8500|GT-S8530|GT-S8600|SCH-A310|SCH-A530|SCH-A570|SCH-A610|SCH-A630|SCH-A650|SCH-A790|SCH-A795|SCH-A850|SCH-A870|SCH-A890|SCH-A930|SCH-A950|SCH-A970|SCH-A990|SCH-I100|SCH-I110|SCH-I400|SCH-I405|SCH-I500|SCH-I510|SCH-I515|SCH-I600|SCH-I730|SCH-I760|SCH-I770|SCH-I830|SCH-I910|SCH-I920|SCH-LC11|SCH-N150|SCH-N300|SCH-R100|SCH-R300|SCH-R351|SCH-R400|SCH-R410|SCH-T300|SCH-U310|SCH-U320|SCH-U350|SCH-U360|SCH-U365|SCH-U370|SCH-U380|SCH-U410|SCH-U430|SCH-U450|SCH-U460|SCH-U470|SCH-U490|SCH-U540|SCH-U550|SCH-U620|SCH-U640|SCH-U650|SCH-U660|SCH-U700|SCH-U740|SCH-U750|SCH-U810|SCH-U820|SCH-U900|SCH-U940|SCH-U960|SCS-26UC|SGH-A107|SGH-A117|SGH-A127|SGH-A137|SGH-A157|SGH-A167|SGH-A177|SGH-A187|SGH-A197|SGH-A227|SGH-A237|SGH-A257|SGH-A437|SGH-A517|SGH-A597|SGH-A637|SGH-A657|SGH-A667|SGH-A687|SGH-A697|SGH-A707|SGH-A717|SGH-A727|SGH-A737|SGH-A747|SGH-A767|SGH-A777|SGH-A797|SGH-A817|SGH-A827|SGH-A837|SGH-A847|SGH-A867|SGH-A877|SGH-A887|SGH-A897|SGH-A927|SGH-B100|SGH-B130|SGH-B200|SGH-B220|SGH-C100|SGH-C110|SGH-C120|SGH-C130|SGH-C140|SGH-C160|SGH-C170|SGH-C180|SGH-C200|SGH-C207|SGH-C210|SGH-C225|SGH-C230|SGH-C417|SGH-C450|SGH-D307|SGH-D347|SGH-D357|SGH-D407|SGH-D415|SGH-D780|SGH-D807|SGH-D980|SGH-E105|SGH-E200|SGH-E315|SGH-E316|SGH-E317|SGH-E335|SGH-E590|SGH-E635|SGH-E715|SGH-E890|SGH-F300|SGH-F480|SGH-I200|SGH-I300|SGH-I320|SGH-I550|SGH-I577|SGH-I600|SGH-I607|SGH-I617|SGH-I627|SGH-I637|SGH-I677|SGH-I700|SGH-I717|SGH-I727|SGH-i747M|SGH-I777|SGH-I780|SGH-I827|SGH-I847|SGH-I857|SGH-I896|SGH-I897|SGH-I900|SGH-I907|SGH-I917|SGH-I927|SGH-I937|SGH-I997|SGH-J150|SGH-J200|SGH-L170|SGH-L700|SGH-M110|SGH-M150|SGH-M200|SGH-N105|SGH-N500|SGH-N600|SGH-N620|SGH-N625|SGH-N700|SGH-N710|SGH-P107|SGH-P207|SGH-P300|SGH-P310|SGH-P520|SGH-P735|SGH-P777|SGH-Q105|SGH-R210|SGH-R220|SGH-R225|SGH-S105|SGH-S307|SGH-T109|SGH-T119|SGH-T139|SGH-T209|SGH-T219|SGH-T229|SGH-T239|SGH-T249|SGH-T259|SGH-T309|SGH-T319|SGH-T329|SGH-T339|SGH-T349|SGH-T359|SGH-T369|SGH-T379|SGH-T409|SGH-T429|SGH-T439|SGH-T459|SGH-T469|SGH-T479|SGH-T499|SGH-T509|SGH-T519|SGH-T539|SGH-T559|SGH-T589|SGH-T609|SGH-T619|SGH-T629|SGH-T639|SGH-T659|SGH-T669|SGH-T679|SGH-T709|SGH-T719|SGH-T729|SGH-T739|SGH-T746|SGH-T749|SGH-T759|SGH-T769|SGH-T809|SGH-T819|SGH-T839|SGH-T919|SGH-T929|SGH-T939|SGH-T959|SGH-T989|SGH-U100|SGH-U200|SGH-U800|SGH-V205|SGH-V206|SGH-X100|SGH-X105|SGH-X120|SGH-X140|SGH-X426|SGH-X427|SGH-X475|SGH-X495|SGH-X497|SGH-X507|SGH-X600|SGH-X610|SGH-X620|SGH-X630|SGH-X700|SGH-X820|SGH-X890|SGH-Z130|SGH-Z150|SGH-Z170|SGH-ZX10|SGH-ZX20|SHW-M110|SPH-A120|SPH-A400|SPH-A420|SPH-A460|SPH-A500|SPH-A560|SPH-A600|SPH-A620|SPH-A660|SPH-A700|SPH-A740|SPH-A760|SPH-A790|SPH-A800|SPH-A820|SPH-A840|SPH-A880|SPH-A900|SPH-A940|SPH-A960|SPH-D600|SPH-D700|SPH-D710|SPH-D720|SPH-I300|SPH-I325|SPH-I330|SPH-I350|SPH-I500|SPH-I600|SPH-I700|SPH-L700|SPH-M100|SPH-M220|SPH-M240|SPH-M300|SPH-M305|SPH-M320|SPH-M330|SPH-M350|SPH-M360|SPH-M370|SPH-M380|SPH-M510|SPH-M540|SPH-M550|SPH-M560|SPH-M570|SPH-M580|SPH-M610|SPH-M620|SPH-M630|SPH-M800|SPH-M810|SPH-M850|SPH-M900|SPH-M910|SPH-M920|SPH-M930|SPH-N100|SPH-N200|SPH-N240|SPH-N300|SPH-N400|SPH-Z400|SWC-E100|SCH-i909|GT-N7100|GT-N8010',
        'Sony'          => 'sony|SonyEricsson|SonyEricssonLT15iv|LT18i|E10i',
        'Asus'          => 'Asus.*Galaxy',
        'Palm'          => 'PalmSource|Palm',
        'Vertu'         => 'Vertu|Vertu.*Ltd|Vertu.*Ascent|Vertu.*Ayxta|Vertu.*Constellation(F|Quest)?|Vertu.*Monika|Vertu.*Signature',
        'Pantech'       => 'PANTECH|IM-A850S|IM-A840S|IM-A830L|IM-A830K|IM-A830S|IM-A820L|IM-A810K|IM-A810S|IM-A800S|IM-T100K|IM-A725L|IM-A780L|IM-A775C|IM-A770K|IM-A760S|IM-A750K|IM-A740S|IM-A730S|IM-A720L|IM-A710K|IM-A690L|IM-A690S|IM-A650S|IM-A630K|IM-A600S|VEGA PTL21|PT003|P8010|ADR910L|P6030|P6020|P9070|P4100|P9060|P5000|CDM8992|TXT8045|ADR8995|IS11PT|P2030|P6010|P8000|PT002|IS06|CDM8999|P9050|PT001|TXT8040|P2020|P9020|P2000|P7040|P7000|C790',
        'Fly'           => 'IQ230|IQ444|IQ450|IQ440|IQ442|IQ441|IQ245|IQ256|IQ236|IQ255|IQ235|IQ245|IQ275|IQ240|IQ285|IQ280|IQ270|IQ260|IQ250',
        'SimValley'     => '\b(SP-80|XT-930|SX-340|XT-930|SX-310|SP-360|SP60|SPT-800|SP-120|SPT-800|SP-140|SPX-5|SPX-8|SP-100|SPX-8|SPX-12)\b',
        'GenericPhone'  => 'Tapatalk|PDA;|PPC;|SAGEM|mmp|pocket|psp|symbian|Smartphone|smartfon|treo|up.browser|up.link|vodafone|wap|nokia|Series40|Series60|S60|SonyEricsson|N900|MAUI.*WAP.*Browser|LG-P500'
    );
    protected $tabletDevices = array(
        'iPad'              => 'iPad|iPad.*Mobile',
        'NexusTablet'       => '^.*Android.*Nexus(((?:(?!Mobile))|(?:(\s(7|10).+))).)*$',
        'SamsungTablet'     => 'SAMSUNG.*Tablet|Galaxy.*Tab|SC-01C|GT-P1000|GT-P1010|GT-P6210|GT-P6800|GT-P6810|GT-P7100|GT-P7300|GT-P7310|GT-P7500|GT-P7510|SCH-I800|SCH-I815|SCH-I905|SGH-I957|SGH-I987|SGH-T849|SGH-T859|SGH-T869|SPH-P100|GT-P3100|GT-P3110|GT-P5100|GT-P5110|GT-P6200|GT-P7320|GT-P7511|GT-N8000|GT-P8510|SGH-I497|SPH-P500|SGH-T779|SCH-I705|SCH-I915|GT-N8013|GT-P3113|GT-P5113|GT-P8110|GT-N8010|GT-N8005|GT-N8020|GT-P1013|GT-P6201|GT-P6810|GT-P7501',
        'Kindle'            => 'Kindle|Silk.*Accelerated',
        'AsusTablet'        => 'Transformer|TF101',
        'BlackBerryTablet'  => 'PlayBook|RIM Tablet',
        'HTCtablet'         => 'HTC Flyer|HTC Jetstream|HTC-P715a|HTC EVO View 4G|PG41200',
        'MotorolaTablet'    => 'xoom|sholest|MZ615|MZ605|MZ505|MZ601|MZ602|MZ603|MZ604|MZ606|MZ607|MZ608|MZ609|MZ615|MZ616|MZ617',
        'NookTablet'        => 'Android.*Nook|NookColor|nook browser|BNTV250A|LogicPD Zoom2',
        'AcerTablet'        => 'Android.*\b(A100|A101|A110|A200|A210|A211|A500|A501|A510|A511|A700|A701|W500|W500P|W501|W501P|W510|W511|W700|G100|G100W|B1-A71)\b',
        'ToshibaTablet'     => 'Android.*(AT100|AT105|AT200|AT205|AT270|AT275|AT300|AT305|AT1S5|AT500|AT570|AT700|AT830)',
        'LGTablet'          => '\bL-06C|LG-V900|LG-V909',
        'YarvikTablet'      => 'Android.*(TAB210|TAB211|TAB224|TAB250|TAB260|TAB264|TAB310|TAB360|TAB364|TAB410|TAB411|TAB420|TAB424|TAB450|TAB460|TAB461|TAB464|TAB465|TAB467|TAB468)',
        'MedionTablet'      => 'Android.*\bOYO\b|LIFE.*(P9212|P9514|P9516|S9512)|LIFETAB',
        'ArnovaTablet'      => 'AN10G2|AN7bG3|AN7fG3|AN8G3|AN8cG3|AN7G3|AN9G3|AN7dG3|AN7dG3ST|AN7dG3ChildPad|AN10bG3|AN10bG3DT',
        'ArchosTablet'      => 'Android.*ARCHOS|101G9|80G9',
        'AinolTablet'       => 'NOVO7|Novo7Aurora|Novo7Basic|NOVO7PALADIN',
        'SonyTablet'        => 'Sony Tablet|Sony Tablet S|SGPT12|SGPT121|SGPT122|SGPT123|SGPT111|SGPT112|SGPT113|SGPT211|SGPT213|EBRD1101|EBRD1102|EBRD1201',
        'CubeTablet'        => 'Android.*(K8GT|U9GT|U10GT|U16GT|U17GT|U18GT|U19GT|U20GT|U23GT|U30GT)|CUBE U8GT',
        'CobyTablet'        => 'MID1042|MID1045|MID1125|MID1126|MID7012|MID7014|MID7034|MID7035|MID7036|MID7042|MID7048|MID7127|MID8042|MID8048|MID8127|MID9042|MID9740|MID9742|MID7022|MID7010',
        'SMiTTablet'        => 'Android.*(\bMID\b|MID-560|MTV-T1200|MTV-PND531|MTV-P1101|MTV-PND530)',
        'RockChipTablet'    => 'Android.*(RK2818|RK2808A|RK2918|RK3066)|RK2738|RK2808A',
        'TelstraTablet'     => 'T-Hub2',
        'FlyTablet'         => 'IQ310|Fly Vision',
        'bqTablet'          => 'bq.*(Elcano|Curie|Edison|Maxwell|Kepler|Pascal|Tesla|Hypatia|Platon|Newton|Livingstone|Cervantes|Avant)',
        'HuaweiTablet'      => 'MediaPad|IDEOS S7|S7-201c|S7-202u|S7-101|S7-103|S7-104|S7-105|S7-106|S7-201|S7-Slim',
        'NecTablet'         => '\bN-06D|\bN-08D',
        'BronchoTablet'     => 'Broncho.*(N701|N708|N802|a710)',
        'VersusTablet'      => 'TOUCHPAD.*[78910]',
        'ZyncTablet'        => 'z1000|Z99 2G|z99|z930|z999|z990|z909|Z919|z900',
        'NabiTablet'        => 'Android.*\bNabi',
        'PlaystationTablet' => 'Playstation.*(Portable|Vita)',
        'GenericTablet'     => 'Android.*\b97D\b|Tablet(?!.*PC)|ViewPad7|MID7015|BNTV250A|LogicPD Zoom2|\bA7EB\b|CatNova8|A1_07|CT704|CT1002|\bM721\b|hp-tablet',
    );
    protected $operatingSystems = array(
        'AndroidOS'         => 'Android',
        'BlackBerryOS'      => 'blackberry|\bBB10\b|rim tablet os',
        'PalmOS'            => 'PalmOS|avantgo|blazer|elaine|hiptop|palm|plucker|xiino',
        'SymbianOS'         => 'Symbian|SymbOS|Series60|Series40|SYB-[0-9]+|\bS60\b',
        'WindowsMobileOS'   => 'Windows CE.*(PPC|Smartphone|Mobile|[0-9]{3}x[0-9]{3})|Window Mobile|Windows Phone [0-9.]+|WCE;',
        'WindowsPhoneOS'   => 'Windows Phone OS|XBLWP7|ZuneWP7',
        'iOS'               => '\biPhone.*Mobile|\biPod|\biPad',
        'MeeGoOS'           => 'MeeGo',
        'MaemoOS'           => 'Maemo',
        'JavaOS'            => 'J2ME/MIDP|Java/',
        'webOS'             => 'webOS|hpwOS',
        'badaOS'            => '\bBada\b',
        'BREWOS'            => 'BREW',
    );
    protected $userAgents = array(
        'Chrome'          => '\bCrMo\b|CriOS|Android.*Chrome/[.0-9]* (Mobile)?',
        'Dolfin'          => '\bDolfin\b',
        'Opera'           => 'Opera.*Mini|Opera.*Mobi|Android.*Opera',
        'Skyfire'         => 'Skyfire',
        'IE'              => 'IEMobile|MSIEMobile',
        'Firefox'         => 'fennec|firefox.*maemo|(Mobile|Tablet).*Firefox|Firefox.*Mobile',
        'Bolt'            => 'bolt',
        'TeaShark'        => 'teashark',
        'Blazer'          => 'Blazer',
        'Safari'          => 'Version.*Mobile.*Safari|Safari.*Mobile',
        'Tizen'           => 'Tizen',
        'UCBrowser'       => 'UC.*Browser|UCWEB',
        'DiigoBrowser'    => 'DiigoBrowser',
        'Puffin'            => 'Puffin',
        'Mercury'          => '\bMercury\b',
        'GenericBrowser'  => 'NokiaBrowser|OviBrowser|SEMC.*Browser|FlyFlow|Minimo|NetFront|Novarra-Vision'
    );
    protected $utilities = array(
        'TV'            => 'SonyDTV115',
        'WebKit'        => '(webkit)[ /]([\w.]+)',
        'Bot'           => 'Googlebot|DoCoMo|YandexBot|bingbot|ia_archiver|AhrefsBot|Ezooms|GSLFbot|WBSearchBot|Twitterbot|TweetmemeBot|Twikle|PaperLiBot|Wotbox|UnwindFetchor|facebookexternalhit',
        'MobileBot'     => 'Googlebot-Mobile|DoCoMo|YahooSeeker/M1A1-R2D2',
    );
    const VER = '([\w._]+)';
    protected $properties = array(

        'Mobile'        => 'Mobile/[VER]',
        'Build'         => 'Build/[VER]',
        'Version'       => 'Version/[VER]',
        'VendorID'      => 'VendorID/[VER]',

        'iPad'          => 'iPad.*CPU[a-z ]+[VER]',
        'iPhone'        => 'iPhone.*CPU[a-z ]+[VER]',
        'iPod'          => 'iPod.*CPU[a-z ]+[VER]',
        'Kindle'        => 'Kindle/[VER]',

        'Chrome'        => 'Chrome/[VER]',
        'CriOS'         => 'CriOS/[VER]',
        'Dolfin'        => 'Dolfin/[VER]',
        'Firefox'       => 'Firefox/[VER]',
        'Fennec'        => 'Fennec/[VER]',
        'IEMobile'      => array('IEMobile/[VER];', 'IEMobile [VER]'),
        'MSIE'          => 'MSIE [VER];',
        'NetFront'      => 'NetFront/[VER]',
        'NokiaBrowser'  => 'NokiaBrowser/[VER]',
        'Opera'         => 'Version/[VER]',
        'Opera Mini'    => 'Opera Mini/[VER]',
        'Opera Mobi'    => 'Version/[VER]',
        'UC Browser'    => 'UC Browser[VER]',
        'Safari'        => 'Version/[VER]',
        'Skyfire'       => 'Skyfire/[VER]',
        'Tizen'         => 'Tizen/[VER]',
        'Webkit'        => 'webkit[ /][VER]',

        'Gecko'         => 'Gecko/[VER]',
        'Trident'       => 'Trident/[VER]',
        'Presto'        => 'Presto/[VER]',

        'Android'          => 'Android [VER]',
        'BlackBerry'       => array('BlackBerry[\w]+/[VER]', 'BlackBerry.*Version/[VER]'),
        'BREW'             => 'BREW [VER]',
        'Java'             => 'Java/[VER]',
        'Windows Phone OS' => 'Windows Phone OS [VER]',
        'Windows Phone'    => 'Windows Phone [VER]',
        'Windows CE'       => 'Windows CE/[VER]',
        'Windows NT'       => 'Windows NT [VER]',
        'Symbian'          => array('SymbianOS/[VER]', 'Symbian/[VER]'),
        'webOS'            => array('webOS/[VER]', 'hpwOS/[VER];'),


    );

    function __construct(){

        $this->setHttpHeaders();
        $this->setUserAgent();

        $this->setMobileDetectionRules();
        $this->setMobileDetectionRulesExtended();

    }


    public function getScriptVersion(){

        return $this->scriptVersion;

    }

    public function setHttpHeaders($httpHeaders = null){

        if(!empty($httpHeaders)){
            $this->httpHeaders = $httpHeaders;
        } else {
            foreach($_SERVER as $key => $value){
                if(substr($key,0,5)=='HTTP_'){
                    $this->httpHeaders[$key] = $value;
                }
            }
        }

    }

    public function getHttpHeaders(){

        return $this->httpHeaders;

    }

    public function setUserAgent($userAgent = null){

        if(!empty($userAgent)){
            $this->userAgent = $userAgent;
        } else {
            $this->userAgent    = isset($this->httpHeaders['HTTP_USER_AGENT']) ? $this->httpHeaders['HTTP_USER_AGENT'] : null;

            if(empty($this->userAgent)){
                $this->userAgent = isset($this->httpHeaders['HTTP_X_DEVICE_USER_AGENT']) ? $this->httpHeaders['HTTP_X_DEVICE_USER_AGENT'] : null;
            }
            if(!empty($this->httpHeaders['HTTP_X_OPERAMINI_PHONE_UA'])){
                $this->userAgent .= ' '.$this->httpHeaders['HTTP_X_OPERAMINI_PHONE_UA'];
            }
        }

    }

    public function getUserAgent(){

        return $this->userAgent;

    }

    function setDetectionType($type = null){

        $this->detectionType = (!empty($type) ? $type : 'mobile');

    }

    public function getPhoneDevices(){

        return $this->phoneDevices;

    }

    public function getTabletDevices(){

        return $this->tabletDevices;

    }

    public function setMobileDetectionRules(){
        $this->mobileDetectionRules = array_merge(
            $this->phoneDevices,
            $this->tabletDevices,
            $this->operatingSystems,
            $this->userAgents
        );

    }

    public function setMobileDetectionRulesExtended(){

        $this->mobileDetectionRulesExtended = array_merge(
            $this->phoneDevices,
            $this->tabletDevices,
            $this->operatingSystems,
            $this->userAgents,
            $this->utilities
        );

    }

    public function getRules()
    {

        if($this->detectionType=='extended'){
            return $this->mobileDetectionRulesExtended;
        } else {
            return $this->mobileDetectionRules;
        }

    }

    public function checkHttpHeadersForMobile(){

        if(
            isset($this->httpHeaders['HTTP_ACCEPT']) &&
                (strpos($this->httpHeaders['HTTP_ACCEPT'], 'application/x-obml2d') !== false ||
                 strpos($this->httpHeaders['HTTP_ACCEPT'], 'application/vnd.rim.html') !== false ||
                 strpos($this->httpHeaders['HTTP_ACCEPT'], 'text/vnd.wap.wml') !== false ||
                 strpos($this->httpHeaders['HTTP_ACCEPT'], 'application/vnd.wap.xhtml+xml') !== false) ||
            isset($this->httpHeaders['HTTP_X_WAP_PROFILE'])             ||
            isset($this->httpHeaders['HTTP_X_WAP_CLIENTID'])            ||
            isset($this->httpHeaders['HTTP_WAP_CONNECTION'])            ||
            isset($this->httpHeaders['HTTP_PROFILE'])                   ||
            isset($this->httpHeaders['HTTP_X_OPERAMINI_PHONE_UA'])      ||
            isset($this->httpHeaders['HTTP_X_NOKIA_IPADDRESS'])         ||
            isset($this->httpHeaders['HTTP_X_NOKIA_GATEWAY_ID'])        ||
            isset($this->httpHeaders['HTTP_X_ORANGE_ID'])               ||
            isset($this->httpHeaders['HTTP_X_VODAFONE_3GPDPCONTEXT'])   ||
            isset($this->httpHeaders['HTTP_X_HUAWEI_USERID'])           ||
            isset($this->httpHeaders['HTTP_UA_OS'])                     ||
            isset($this->httpHeaders['HTTP_X_MOBILE_GATEWAY'])          ||
            isset($this->httpHeaders['HTTP_X_ATT_DEVICEID'])            ||
            ( isset($this->httpHeaders['HTTP_UA_CPU']) &&
                    $this->httpHeaders['HTTP_UA_CPU'] == 'ARM'
            )
        ){

            return true;

        }

        return false;

    }

    public function __call($name, $arguments)
    {

        $this->setDetectionType('mobile');

        $key = substr($name, 2);
        return $this->matchUAAgainstKey($key);

    }

    private function matchDetectionRulesAgainstUA($userAgent = null){

        foreach($this->getRules() as $_regex){
            if(empty($_regex)){ continue; }
            if( $this->match($_regex, $userAgent) ){
                return true;
            }
        }

        return false;

    }

    private function matchUAAgainstKey($key, $userAgent = null){

        $key = strtolower($key);
        $_rules = array_change_key_case($this->getRules());

        if(array_key_exists($key, $_rules)){
            if(empty($_rules[$key])){ return null; }
            return $this->match($_rules[$key], $userAgent);
        }

        return false;

    }

    public function isMobile($userAgent = null, $httpHeaders = null) {

        if($httpHeaders){ $this->setHttpHeaders($httpHeaders); }
        if($userAgent){ $this->setUserAgent($userAgent); }

        $this->setDetectionType('mobile');

        if ($this->checkHttpHeadersForMobile()) {
            return true;
        } else {
            return $this->matchDetectionRulesAgainstUA();
        }

    }

    public function isTablet($userAgent = null, $httpHeaders = null) {

        $this->setDetectionType('mobile');

        foreach($this->tabletDevices as $_regex){
            if($this->match($_regex, $userAgent)){
                return true;
            }
        }

        return false;

    }

    public function is($key, $userAgent = null, $httpHeaders = null){


        if($httpHeaders) $this->setHttpHeaders($httpHeaders);
        if($userAgent) $this->setUserAgent($userAgent);

        $this->setDetectionType('extended');

	    return $this->matchUAAgainstKey( $key );

    }

	public function getOperatingSystems() {

		return $this->operatingSystems;

	}

	function match($regex, $userAgent = null) {
		$regex = str_replace('/', '\/', $regex);

		if ( is_string($userAgent) ) {
			$subject = $userAgent;
		} else {
			$subject = $this->userAgent ?? '';
		}

		return (bool) preg_match('/' . $regex . '/is', $subject);
	}

    function getProperties(){

        return $this->properties;

    }

    function prepareVersionNo($ver){

        $ver = str_replace(array('_', ' ', '/'), array('.', '.', '.'), $ver);
        $arrVer = explode('.', $ver, 2);
        $arrVer[1] = @str_replace('.', '', $arrVer[1]);
        $ver = (float)implode('.', $arrVer);

        return $ver;

    }

    function version($propertyName){

        $properties = $this->getProperties();

        if(stripos($this->userAgent, $propertyName)!==false){

            $properties[$propertyName] = (array)$properties[$propertyName];

            foreach($properties[$propertyName] as $propertyMatchString){

                $propertyPattern = str_replace('[VER]', self::VER, $propertyMatchString);

                $propertyPattern = str_replace('/', '\/', $propertyPattern);

                preg_match('/'.$propertyPattern.'/is', $this->userAgent, $match);

                if(!empty($match[1])){
                    $version = $this->prepareVersionNo($match[1]);
                    return $version;
                }

            }

            return 0;

        }

        return false;

    }

    function mobileGrade(){

        $isMobile = $this->isMobile();

        if(
            $this->version('iPad')>=4.3 ||
            $this->version('iPhone')>=3.1 ||
            $this->version('iPod')>=3.1 ||

            ( $this->version('Android')>2.1 && $this->is('Webkit') ) ||

            $this->version('Windows Phone OS')>=7.0 ||

            $this->version('BlackBerry')>=6.0 ||
            $this->match('Playbook.*Tablet') ||

            ( $this->version('webOS')>=1.4 && $this->match('Palm|Pre|Pixi') ) ||
            $this->match('hp.*TouchPad') ||

            ( $this->is('Firefox') && $this->version('Firefox')>=12 ) ||

            ( $this->is('Chrome') && $this->is('AndroidOS') && $this->version('Android')>=4.0 ) ||

            ( $this->is('Skyfire') && $this->version('Skyfire')>=4.1 && $this->is('AndroidOS') && $this->version('Android')>=2.3 ) ||

            ( $this->is('Opera') && $this->version('Opera Mobi')>11 && $this->is('AndroidOS') ) ||

            $this->is('MeeGoOS') ||

            $this->is('Tizen') ||

            $this->is('Dolfin') && $this->version('Bada')>=2.0 ||

            ( ($this->is('UC Browser') || $this->is('Dolfin')) && $this->version('Android')>=2.3 ) ||

            ( $this->match('Kindle Fire') ||
            $this->is('Kindle') && $this->version('Kindle')>=3.0 ) ||

            $this->is('AndroidOS') && $this->is('NookTablet') ||

            $this->version('Chrome')>=11 && !$isMobile ||

            $this->version('Safari')>=5.0 && !$isMobile ||

            $this->version('Firefox')>=4.0 && !$isMobile ||

            $this->version('MSIE')>=7.0 && !$isMobile ||

            $this->version('Opera')>=10 && !$isMobile


        ){
            return 'A';
        }

        if(
            $this->version('BlackBerry')>=5 && $this->version('BlackBerry')<6 ||

            ( $this->version('Opera Mini')>=5.0 && $this->version('Opera Mini')<=6.5 &&
            ($this->version('Android')>=2.3 || $this->is('iOS')) ) ||

            $this->match('NokiaN8|NokiaC7|N97.*Series60|Symbian/3') ||

            $this->version('Opera Mobi')>=11 && $this->is('SymbianOS')

            ){
            return 'B';
        }

        if(
            $this->version('BlackBerry')<5.0 ||
            $this->match('MSIEMobile|Windows CE.*Mobile') || $this->version('Windows Mobile')<=5.2


        ){

            return 'C';

        }

        return 'C';


    }


}
