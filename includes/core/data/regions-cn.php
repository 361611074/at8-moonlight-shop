<?php
/**
 * 内置中国行政区划数据（省级行政区 + 主要地级市）。
 *
 * 结构：['CN-XX' => ['name' => '省名', 'cities' => ['CN-XX-YY' => '市名', ...]], ...]
 *
 * 覆盖范围：全国 34 个省级行政区（23 省 / 5 自治区 / 4 直辖市 / 2 特别行政区，含港澳台），
 * 每省收录主要地级市 5-20 个（直辖市与特别行政区收录自身/主要区域），
 * 总量约 340 市。不含区县——需要全量区县时通过 moonlight_regions 过滤器
 * 接入 Pro 数据源替换本表（见 Moonlight_Region_Provider::data()）。
 *
 * 区码规则：省级 = CN-二字拼音缩写（如 CN-GD）；市级 = 省码-市拼音缩写（如 CN-GD-GZ）。
 * 数据为 PHP 数组硬编码，无 DB 依赖，可安全地在测试环境加载。
 *
 * @package Moonlight_Shop
 */

if (!defined('ABSPATH')) {
    exit;
}

return array(
    // ---- 直辖市（4）----
    'CN-BJ' => array('name' => '北京', 'cities' => array('CN-BJ-BJ' => '北京市')),
    'CN-TJ' => array('name' => '天津', 'cities' => array('CN-TJ-TJ' => '天津市')),
    'CN-SH' => array('name' => '上海', 'cities' => array('CN-SH-SH' => '上海市')),
    'CN-CQ' => array('name' => '重庆', 'cities' => array('CN-CQ-CQ' => '重庆市')),

    // ---- 省份（23）----
    'CN-HE' => array('name' => '河北', 'cities' => array(
        'CN-HE-SJZ' => '石家庄市', 'CN-HE-TS' => '唐山市', 'CN-HE-QHD' => '秦皇岛市', 'CN-HE-HD' => '邯郸市',
        'CN-HE-XT' => '邢台市', 'CN-HE-BD' => '保定市', 'CN-HE-ZJK' => '张家口市', 'CN-HE-CD' => '承德市',
        'CN-HE-CZ' => '沧州市', 'CN-HE-LF' => '廊坊市', 'CN-HE-HS' => '衡水市',
    )),
    'CN-SX' => array('name' => '山西', 'cities' => array(
        'CN-SX-TY' => '太原市', 'CN-SX-DT' => '大同市', 'CN-SX-YQ' => '阳泉市', 'CN-SX-CZ' => '长治市',
        'CN-SX-JC' => '晋城市', 'CN-SX-SZ' => '朔州市', 'CN-SX-JZ' => '晋中市', 'CN-SX-YC' => '运城市',
        'CN-SX-XZ' => '忻州市', 'CN-SX-LF' => '临汾市', 'CN-SX-LL' => '吕梁市',
    )),
    'CN-NM' => array('name' => '内蒙古', 'cities' => array(
        'CN-NM-HHHT' => '呼和浩特市', 'CN-NM-BT' => '包头市', 'CN-NM-WH' => '乌海市', 'CN-NM-CF' => '赤峰市',
        'CN-NM-TL' => '通辽市', 'CN-NM-EEDS' => '鄂尔多斯市', 'CN-NM-HLBE' => '呼伦贝尔市',
        'CN-NM-BYNE' => '巴彦淖尔市', 'CN-NM-WLCB' => '乌兰察布市',
    )),
    'CN-LN' => array('name' => '辽宁', 'cities' => array(
        'CN-LN-SY' => '沈阳市', 'CN-LN-DL' => '大连市', 'CN-LN-AS' => '鞍山市', 'CN-LN-FS' => '抚顺市',
        'CN-LN-BX' => '本溪市', 'CN-LN-DD' => '丹东市', 'CN-LN-JZ' => '锦州市', 'CN-LN-YK' => '营口市',
        'CN-LN-FX' => '阜新市', 'CN-LN-LY' => '辽阳市', 'CN-LN-PJ' => '盘锦市', 'CN-LN-TL' => '铁岭市',
        'CN-LN-CY' => '朝阳市', 'CN-LN-HLD' => '葫芦岛市',
    )),
    'CN-JL' => array('name' => '吉林', 'cities' => array(
        'CN-JL-CC' => '长春市', 'CN-JL-JL' => '吉林市', 'CN-JL-SP' => '四平市', 'CN-JL-LY' => '辽源市',
        'CN-JL-TH' => '通化市', 'CN-JL-BS' => '白山市', 'CN-JL-SY' => '松原市', 'CN-JL-BC' => '白城市',
        'CN-JL-YB' => '延边朝鲜族自治州',
    )),
    'CN-HL' => array('name' => '黑龙江', 'cities' => array(
        'CN-HL-HEB' => '哈尔滨市', 'CN-HL-QQHE' => '齐齐哈尔市', 'CN-HL-JX' => '鸡西市', 'CN-HL-HG' => '鹤岗市',
        'CN-HL-SYS' => '双鸭山市', 'CN-HL-DQ' => '大庆市', 'CN-HL-YC' => '伊春市', 'CN-HL-JMS' => '佳木斯市',
        'CN-HL-QTH' => '七台河市', 'CN-HL-MDJ' => '牡丹江市', 'CN-HL-HH' => '黑河市', 'CN-HL-SH' => '绥化市',
    )),
    'CN-JS' => array('name' => '江苏', 'cities' => array(
        'CN-JS-NJ' => '南京市', 'CN-JS-WX' => '无锡市', 'CN-JS-XZ' => '徐州市', 'CN-JS-CZ' => '常州市',
        'CN-JS-SZ' => '苏州市', 'CN-JS-NT' => '南通市', 'CN-JS-LYG' => '连云港市', 'CN-JS-HA' => '淮安市',
        'CN-JS-YC' => '盐城市', 'CN-JS-YZ' => '扬州市', 'CN-JS-ZJ' => '镇江市', 'CN-JS-TZ' => '泰州市',
        'CN-JS-SQ' => '宿迁市',
    )),
    'CN-ZJ' => array('name' => '浙江', 'cities' => array(
        'CN-ZJ-HZ' => '杭州市', 'CN-ZJ-NB' => '宁波市', 'CN-ZJ-WZ' => '温州市', 'CN-ZJ-JX' => '嘉兴市',
        'CN-ZJ-HU' => '湖州市', 'CN-ZJ-SX' => '绍兴市', 'CN-ZJ-JH' => '金华市', 'CN-ZJ-QZ' => '衢州市',
        'CN-ZJ-ZS' => '舟山市', 'CN-ZJ-TZ' => '台州市', 'CN-ZJ-LS' => '丽水市',
    )),
    'CN-AH' => array('name' => '安徽', 'cities' => array(
        'CN-AH-HF' => '合肥市', 'CN-AH-WH' => '芜湖市', 'CN-AH-BB' => '蚌埠市', 'CN-AH-HN' => '淮南市',
        'CN-AH-MAS' => '马鞍山市', 'CN-AH-HB' => '淮北市', 'CN-AH-TL' => '铜陵市', 'CN-AH-AQ' => '安庆市',
        'CN-AH-HS' => '黄山市', 'CN-AH-CZ' => '滁州市', 'CN-AH-FY' => '阜阳市', 'CN-AH-SZ' => '宿州市',
        'CN-AH-LA' => '六安市', 'CN-AH-BZ' => '亳州市', 'CN-AH-CHZ' => '池州市', 'CN-AH-XC' => '宣城市',
    )),
    'CN-FJ' => array('name' => '福建', 'cities' => array(
        'CN-FJ-FZ' => '福州市', 'CN-FJ-XM' => '厦门市', 'CN-FJ-PT' => '莆田市', 'CN-FJ-SM' => '三明市',
        'CN-FJ-QZ' => '泉州市', 'CN-FJ-ZZ' => '漳州市', 'CN-FJ-NP' => '南平市', 'CN-FJ-LY' => '龙岩市',
        'CN-FJ-ND' => '宁德市',
    )),
    'CN-JX' => array('name' => '江西', 'cities' => array(
        'CN-JX-NC' => '南昌市', 'CN-JX-JDZ' => '景德镇市', 'CN-JX-PX' => '萍乡市', 'CN-JX-JJ' => '九江市',
        'CN-JX-XY' => '新余市', 'CN-JX-YT' => '鹰潭市', 'CN-JX-GZ' => '赣州市', 'CN-JX-JA' => '吉安市',
        'CN-JX-YC' => '宜春市', 'CN-JX-FZ' => '抚州市', 'CN-JX-SR' => '上饶市',
    )),
    'CN-SD' => array('name' => '山东', 'cities' => array(
        'CN-SD-JN' => '济南市', 'CN-SD-QD' => '青岛市', 'CN-SD-ZB' => '淄博市', 'CN-SD-ZZ' => '枣庄市',
        'CN-SD-DY' => '东营市', 'CN-SD-YT' => '烟台市', 'CN-SD-WF' => '潍坊市', 'CN-SD-JIN' => '济宁市',
        'CN-SD-TA' => '泰安市', 'CN-SD-WH' => '威海市', 'CN-SD-RZ' => '日照市', 'CN-SD-LY' => '临沂市',
        'CN-SD-DZ' => '德州市', 'CN-SD-LC' => '聊城市', 'CN-SD-BZ' => '滨州市', 'CN-SD-HZ' => '菏泽市',
    )),
    'CN-HA' => array('name' => '河南', 'cities' => array(
        'CN-HA-ZZ' => '郑州市', 'CN-HA-KF' => '开封市', 'CN-HA-LY' => '洛阳市', 'CN-HA-PDS' => '平顶山市',
        'CN-HA-AY' => '安阳市', 'CN-HA-HB' => '鹤壁市', 'CN-HA-XX' => '新乡市', 'CN-HA-JZ' => '焦作市',
        'CN-HA-PY' => '濮阳市', 'CN-HA-XC' => '许昌市', 'CN-HA-LH' => '漯河市', 'CN-HA-SMX' => '三门峡市',
        'CN-HA-NY' => '南阳市', 'CN-HA-SQ' => '商丘市', 'CN-HA-XYY' => '信阳市', 'CN-HA-ZK' => '周口市',
        'CN-HA-ZMD' => '驻马店市',
    )),
    'CN-HB' => array('name' => '湖北', 'cities' => array(
        'CN-HB-WH' => '武汉市', 'CN-HB-HS' => '黄石市', 'CN-HB-SY' => '十堰市', 'CN-HB-YC' => '宜昌市',
        'CN-HB-XY' => '襄阳市', 'CN-HB-EZ' => '鄂州市', 'CN-HB-JM' => '荆门市', 'CN-HB-XG' => '孝感市',
        'CN-HB-JZ' => '荆州市', 'CN-HB-HG' => '黄冈市', 'CN-HB-XN' => '咸宁市', 'CN-HB-SZ' => '随州市',
        'CN-HB-ES' => '恩施土家族苗族自治州',
    )),
    'CN-HN' => array('name' => '湖南', 'cities' => array(
        'CN-HN-CS' => '长沙市', 'CN-HN-ZZ' => '株洲市', 'CN-HN-XT' => '湘潭市', 'CN-HN-HY' => '衡阳市',
        'CN-HN-SY' => '邵阳市', 'CN-HN-YY' => '岳阳市', 'CN-HN-CD' => '常德市', 'CN-HN-ZJJ' => '张家界市',
        'CN-HN-YI' => '益阳市', 'CN-HN-CZ' => '郴州市', 'CN-HN-YZ' => '永州市', 'CN-HN-HH' => '怀化市',
        'CN-HN-LD' => '娄底市', 'CN-HN-XX' => '湘西土家族苗族自治州',
    )),
    'CN-GD' => array('name' => '广东', 'cities' => array(
        'CN-GD-GZ' => '广州市', 'CN-GD-SG' => '韶关市', 'CN-GD-SZ' => '深圳市', 'CN-GD-ZH' => '珠海市',
        'CN-GD-ST' => '汕头市', 'CN-GD-FS' => '佛山市', 'CN-GD-JM' => '江门市', 'CN-GD-ZJ' => '湛江市',
        'CN-GD-MM' => '茂名市', 'CN-GD-ZQ' => '肇庆市', 'CN-GD-HZ' => '惠州市', 'CN-GD-MZ' => '梅州市',
        'CN-GD-SW' => '汕尾市', 'CN-GD-HY' => '河源市', 'CN-GD-YJ' => '阳江市', 'CN-GD-QY' => '清远市',
        'CN-GD-DG' => '东莞市', 'CN-GD-ZS' => '中山市', 'CN-GD-CZ' => '潮州市', 'CN-GD-JY' => '揭阳市',
    )),
    'CN-GX' => array('name' => '广西', 'cities' => array(
        'CN-GX-NN' => '南宁市', 'CN-GX-LZ' => '柳州市', 'CN-GX-GL' => '桂林市', 'CN-GX-WZ' => '梧州市',
        'CN-GX-BH' => '北海市', 'CN-GX-FCG' => '防城港市', 'CN-GX-QZ' => '钦州市', 'CN-GX-GG' => '贵港市',
        'CN-GX-YL' => '玉林市', 'CN-GX-BS' => '百色市', 'CN-GX-HZ' => '贺州市', 'CN-GX-HC' => '河池市',
        'CN-GX-LB' => '来宾市', 'CN-GX-CZ' => '崇左市',
    )),
    'CN-HI' => array('name' => '海南', 'cities' => array(
        'CN-HI-HK' => '海口市', 'CN-HI-SY' => '三亚市', 'CN-HI-SS' => '三沙市', 'CN-HI-DZ' => '儋州市',
        'CN-HI-QH' => '琼海市', 'CN-HI-WC' => '文昌市', 'CN-HI-WN' => '万宁市', 'CN-HI-WZS' => '五指山市',
    )),
    'CN-SC' => array('name' => '四川', 'cities' => array(
        'CN-SC-CD' => '成都市', 'CN-SC-ZG' => '自贡市', 'CN-SC-PZH' => '攀枝花市', 'CN-SC-LZ' => '泸州市',
        'CN-SC-DY' => '德阳市', 'CN-SC-MY' => '绵阳市', 'CN-SC-GY' => '广元市', 'CN-SC-SN' => '遂宁市',
        'CN-SC-NJ' => '内江市', 'CN-SC-LS' => '乐山市', 'CN-SC-NC' => '南充市', 'CN-SC-MS' => '眉山市',
        'CN-SC-YB' => '宜宾市', 'CN-SC-GA' => '广安市', 'CN-SC-DZ' => '达州市', 'CN-SC-YA' => '雅安市',
        'CN-SC-BZ' => '巴中市', 'CN-SC-ZY' => '资阳市',
    )),
    'CN-GZ' => array('name' => '贵州', 'cities' => array(
        'CN-GZ-GY' => '贵阳市', 'CN-GZ-LPS' => '六盘水市', 'CN-GZ-ZY' => '遵义市', 'CN-GZ-AS' => '安顺市',
        'CN-GZ-BJ' => '毕节市', 'CN-GZ-TR' => '铜仁市', 'CN-GZ-QXN' => '黔西南布依族苗族自治州',
        'CN-GZ-QDN' => '黔东南苗族侗族自治州', 'CN-GZ-QN' => '黔南布依族苗族自治州',
    )),
    'CN-YN' => array('name' => '云南', 'cities' => array(
        'CN-YN-KM' => '昆明市', 'CN-YN-QJ' => '曲靖市', 'CN-YN-YX' => '玉溪市', 'CN-YN-BS' => '保山市',
        'CN-YN-ZT' => '昭通市', 'CN-YN-LJ' => '丽江市', 'CN-YN-PE' => '普洱市', 'CN-YN-LC' => '临沧市',
        'CN-YN-XSBN' => '西双版纳傣族自治州', 'CN-YN-DH' => '德宏傣族景颇族自治州', 'CN-YN-NJ' => '怒江傈僳族自治州',
        'CN-YN-DQ' => '迪庆藏族自治州', 'CN-YN-HH' => '红河哈尼族彝族自治州', 'CN-YN-WS' => '文山壮族苗族自治州',
        'CN-YN-CX' => '楚雄彝族自治州', 'CN-YN-DL' => '大理白族自治州',
    )),
    'CN-XZ' => array('name' => '西藏', 'cities' => array(
        'CN-XZ-LS' => '拉萨市', 'CN-XZ-RKZ' => '日喀则市', 'CN-XZ-CD' => '昌都市', 'CN-XZ-LZ' => '林芝市',
        'CN-XZ-SN' => '山南市', 'CN-XZ-NQ' => '那曲市', 'CN-XZ-AL' => '阿里地区',
    )),
    'CN-SN' => array('name' => '陕西', 'cities' => array(
        'CN-SN-XA' => '西安市', 'CN-SN-TC' => '铜川市', 'CN-SN-BJ' => '宝鸡市', 'CN-SN-XY' => '咸阳市',
        'CN-SN-WN' => '渭南市', 'CN-SN-YA' => '延安市', 'CN-SN-HZ' => '汉中市', 'CN-SN-YL' => '榆林市',
        'CN-SN-AK' => '安康市', 'CN-SN-SL' => '商洛市',
    )),
    'CN-GS' => array('name' => '甘肃', 'cities' => array(
        'CN-GS-LZ' => '兰州市', 'CN-GS-JYG' => '嘉峪关市', 'CN-GS-JC' => '金昌市', 'CN-GS-BY' => '白银市',
        'CN-GS-TS' => '天水市', 'CN-GS-WW' => '武威市', 'CN-GS-ZY' => '张掖市', 'CN-GS-PL' => '平凉市',
        'CN-GS-JQ' => '酒泉市', 'CN-GS-QY' => '庆阳市', 'CN-GS-DX' => '定西市', 'CN-GS-LN' => '陇南市',
        'CN-GS-LX' => '临夏回族自治州', 'CN-GS-GN' => '甘南藏族自治州',
    )),
    'CN-QH' => array('name' => '青海', 'cities' => array(
        'CN-QH-XN' => '西宁市', 'CN-QH-HD' => '海东市', 'CN-QH-HB' => '海北藏族自治州',
        'CN-QH-HN' => '黄南藏族自治州', 'CN-QH-HNZ' => '海南藏族自治州', 'CN-QH-GS' => '果洛藏族自治州',
        'CN-QH-HX' => '海西蒙古族藏族自治州', 'CN-QH-YS' => '玉树藏族自治州',
    )),
    'CN-NX' => array('name' => '宁夏', 'cities' => array(
        'CN-NX-YC' => '银川市', 'CN-NX-SZS' => '石嘴山市', 'CN-NX-WZ' => '吴忠市', 'CN-NX-GY' => '固原市',
        'CN-NX-ZW' => '中卫市',
    )),
    'CN-XJ' => array('name' => '新疆', 'cities' => array(
        'CN-XJ-WLMQ' => '乌鲁木齐市', 'CN-XJ-KLMY' => '克拉玛依市', 'CN-XJ-TLF' => '吐鲁番市', 'CN-XJ-HM' => '哈密市',
        'CN-XJ-CJ' => '昌吉回族自治州', 'CN-XJ-BETL' => '博尔塔拉蒙古自治州', 'CN-XJ-BYGL' => '巴音郭楞蒙古自治州',
        'CN-XJ-AKS' => '阿克苏地区', 'CN-XJ-KZLS' => '克孜勒苏柯尔克孜自治州', 'CN-XJ-KS' => '喀什地区',
        'CN-XJ-HT' => '和田地区', 'CN-XJ-YL' => '伊犁哈萨克自治州', 'CN-XJ-TC' => '塔城地区', 'CN-XJ-ALT' => '阿勒泰地区',
    )),
    'CN-TW' => array('name' => '台湾', 'cities' => array(
        'CN-TW-TPE' => '台北市', 'CN-TW-NTPC' => '新北市', 'CN-TW-TY' => '桃园市', 'CN-TW-TZ' => '台中市',
        'CN-TW-TN' => '台南市', 'CN-TW-KH' => '高雄市', 'CN-TW-KL' => '基隆市', 'CN-TW-HC' => '新竹市',
        'CN-TW-CY' => '嘉义市',
    )),

    // ---- 特别行政区（2）----
    'CN-HK' => array('name' => '香港', 'cities' => array(
        'CN-HK-HKI' => '香港島', 'CN-HK-KLN' => '九龍', 'CN-HK-NT' => '新界',
    )),
    'CN-MO' => array('name' => '澳门', 'cities' => array(
        'CN-MO-MP' => '澳門半島', 'CN-MO-TA' => '氹仔', 'CN-MO-CO' => '路環',
    )),
);
