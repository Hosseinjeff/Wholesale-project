<?php
/** Plugin Name: Molavi Native Reference Candidate Matching */
declare(strict_types=1);
defined('ABSPATH') || exit;

function molavi_domain_match_name($value): string {
    $text=(string)molavi_domain_py_value($value);$matches=array_merge(molavi_domain_measurement_matches($text,'volume'),molavi_domain_measurement_matches($text,'weight'));
    usort($matches,static fn($a,$b)=>$b['start']<=>$a['start']);foreach($matches as $match)$text=substr_replace($text,$match['display'],$match['start'],$match['length']);
    return molavi_domain_normalize_name($text);
}
function molavi_domain_match_number($value): int|float {
    if(is_bool($value))return 0;$raw=molavi_domain_digits(molavi_domain_py_value($value,0));$raw=preg_replace('/(?<=\d)_(?=\d)/u','',$raw);
    return is_numeric($raw)&&is_finite((float)$raw)&&(float)$raw>0?(float)$raw:0;
}
function molavi_domain_match_package(array $product): float {
    if($value=molavi_domain_match_number($product['package_count']??null))return $value;
    $text=(string)molavi_domain_py_value($product['packaging']??null,molavi_domain_py_value($product['name']??null));
    return preg_match('/(*UCP)(?<!\d)(\d{1,4})\s*(?:عددی|تایی|عدد|ف|تکه|قوطی|بسته)/u',molavi_domain_digits($text),$m)?(float)$m[1]:0.0;
}
function molavi_domain_offer_attributes(array $offer): array {
    $map=['name'=>'Product Name','brand'=>'Brand','category'=>'Category','variation_type'=>'Variation Type','package_count'=>'Package Count','packaging'=>'Packaging','volume'=>'Volume','weight'=>'Weight'];$out=[];
    foreach($map as $key=>$field)$out[$key]=molavi_domain_py_value($offer[$field]??null);return $out;
}
function molavi_domain_reference_signature(array $product): string {
    $text=static fn($value)=>molavi_domain_normalize_name((string)molavi_domain_py_value($value));$pack=molavi_domain_match_package($product);
    return molavi_domain_hash([molavi_domain_match_name(molavi_domain_py_value($product['clean_name']??null,$product['name']??'')),
        $text($product['brand']??null),$text($product['category']??null),$text(molavi_domain_py_value($product['variant']??null,$product['variation_type']??'')),
        $pack?molavi_domain_cell_text($pack):'',$text($product['volume']??null),$text($product['weight']??null)]);
}
/** RapidFuzz ratio is normalized Indel similarity: twice the Unicode LCS length. */
function molavi_domain_match_ratio(string $left,string $right): float {
    if($left===$right)return 1.0;$a=preg_split('//u',$left,-1,PREG_SPLIT_NO_EMPTY);$b=preg_split('//u',$right,-1,PREG_SPLIT_NO_EMPTY);
    if(!$a||!$b)return 0.0;if(count($a)<count($b))[$a,$b]=[$b,$a];$width=count($b);
    // Most product names fit one signed-safe machine word. The same LCS is then
    // computed with integer bit operations instead of a quadratic cell matrix.
    if(PHP_INT_SIZE>=8&&$width<=60){$masks=[];foreach($b as $index=>$character)$masks[$character]=($masks[$character]??0)|(1<<$index);$state=0;
        foreach($a as $character){$x=($masks[$character]??0)|$state;$y=($state<<1)|1;$state=$x&~($x-$y);}
        $length=0;while($state){$state&=$state-1;$length++;}return 2.0*$length/(count($a)+$width);}
    $previous=array_fill(0,$width+1,0);
    foreach($a as $character){$row=[0];for($i=1;$i<=$width;$i++)$row[$i]=$character===$b[$i-1]?$previous[$i-1]+1:max($previous[$i],$row[$i-1]);$previous=$row;}
    return 2.0*$previous[$width]/(count($a)+$width);
}
function molavi_domain_matching_reference(array $row): array {
    $reference=['reference_id'=>(string)molavi_domain_py_value($row[16],$row[2]),'name'=>molavi_domain_cell_text($row[0]),'clean_name'=>molavi_domain_cell_text(molavi_domain_py_value($row[1],$row[0]))];
    foreach(['unit_price'=>4,'package_price'=>3,'consumer_price'=>5,'package_count'=>6] as $key=>$index)$reference[$key]=molavi_domain_match_number($row[$index]);
    foreach(['unit_label'=>7,'volume'=>8,'weight'=>9,'variant'=>11,'brand'=>12,'category'=>13] as $key=>$index)$reference[$key]=(string)molavi_domain_py_value($row[$index]);return $reference;
}
/** Strip pack wording and generic flavour-count phrases from a compared
 * name (iteration 1, D §5.2): the supplier name keeps them (the prompt
 * orders the model to copy them) while the catalog clean title strips
 * them, so leaving them in the compared string punishes true matches
 * and rewards junk rows that share only generic words (۴ طعم موجود
 * ۲۴ عددی). Size tokens stay: they are identity, not pack wording. */
function molavi_domain_identity_strip_compared_name(string $text): string {
    $text=preg_replace('/\d+\s*(?:طعم|مزه|رنگ|مدل|نوع)(?:\s*(?:موجود|مختلف|متنوع))?/u',' ',$text)??$text;
    $text=preg_replace('/\d+\s*(?:عددی|عدد|ع|تایی|تا|بسته|کارتن|باکس|قوطی|تکه)/u',' ',$text)??$text;
    $text=preg_replace('/(?:کارتن|باکس|بسته)\s*\d+/u',' ',$text)??$text;
    return trim(preg_replace('/\s+/u',' ',$text)??$text);
}
function molavi_domain_match_values(array $product): array {
    $text=static fn($value)=>molavi_domain_normalize_name((string)molavi_domain_py_value($value));$pack=molavi_domain_match_package($product);
    return ['name'=>molavi_domain_identity_strip_compared_name(molavi_domain_match_name(molavi_domain_py_value($product['clean_name']??null,$product['name']??''))),
        'brand'=>$text($product['brand']??null),'category'=>$text($product['category']??null),'variant'=>$text(molavi_domain_py_value($product['variant']??null,$product['variation_type']??'')),
        'package_count'=>$pack?molavi_domain_cell_text($pack):'','volume'=>molavi_domain_match_name($product['volume']??null),'weight'=>molavi_domain_match_name($product['weight']??null)];
}
/** Full-identity tokenisation (catalog-extraction iteration 1).
 * Rezvan titles are compact coded strings (glued compounds like
 * هزارجزیره470گ, (Nف) price codes, Nع pack codes, ***, (قدیم),
 * (R150)). The identity layer de-glues them, strips the codes out of
 * the compared name, and compares token sets order-free, so brand +
 * product + variant + size + pack + price can agree even when the LCS
 * name string caps at ~0.73-0.79. The string name_similarity path is
 * untouched: identity is a parallel path, never a replacement. */
function molavi_domain_identity_deglue(string $text): string {
    $text=preg_replace('/(?<=\pL)(?=\pN)/u',' ',$text)??$text;
    $text=preg_replace('/(?<=\pN)(?=\pL)/u',' ',$text)??$text;
    return $text;
}
/** De-glued content tokens of a title, with sizes, pack counts, price
 * codes, flavour-count phrases and connector words removed. Returns
 * ['tokens'=>string[], 'stale'=>bool]; tokens are UNSPLIT compounds —
 * splitting against a lexicon happens in
 * molavi_domain_identity_split_tokens() at comparison time, so the
 * snapshot lexicon and the other side's tokens can both supply parts. */
/** Known extraction typos mapped to their brand (owner-visible allowlist).
 * The matcher never fuzzy-matches a brand: a misspelling matches only
 * when it is listed here. 'تمیز' is Gemini's misreading of 'تیمز'. */
function molavi_domain_identity_brand_aliases(): array {
    return ['تمیز'=>'تیمز'];
}
function molavi_domain_identity_alias_brand(string $brand): string {
    $aliases=molavi_domain_identity_brand_aliases();
    $norm=molavi_domain_normalize_name($brand);
    return $aliases[$norm]??$norm;
}
function molavi_domain_identity_parse_title(string $text): array {
    $stale=str_contains($text,'قدیم');
    // Sachet/format codes `(1*3)` / bare `1*3` are identity: they
    // distinguish (1*2) from (1*3) rows. Extract them BEFORE de-gluing
    // and normalisation destroy the `*`, and keep them as `1x3` tokens.
    $formats=[];
    if(preg_match_all('/\(\s*(\d+)\s*[*×xX]\s*(\d+)\s*\)/u',$text,$m,PREG_SET_ORDER))
        foreach($m as $match)$formats[]=$match[1].'x'.$match[2];
    $text=preg_replace('/\(\s*\d+\s*[*×xX]\s*\d+\s*\)/u',' ',$text);
    if(preg_match_all('/(?<!\d)(\d+)\s*[*×]\s*(\d+)(?!\d)/u',$text,$m,PREG_SET_ORDER))
        foreach($m as $match)$formats[]=$match[1].'x'.$match[2];
    $text=preg_replace('/(?<!\d)\d+\s*[*×]\s*\d+(?!\d)/u',' ',$text);
    // Bare `N M` single-digit pairs are the same sachet format with the
    // `*` lost in extraction (`کافی میکس 1 3 تیمز` came from `(1*3)`).
    // Without them B2 (1*3) is indistinguishable from هات چاکلت.
    if(preg_match_all('/(?<!\d)([1-9]) ([1-9])(?!\d)/u',$text,$m,PREG_SET_ORDER))
        foreach($m as $match)$formats[]=$match[1].'x'.$match[2];
    $text=preg_replace('/(?<!\d)[1-9] [1-9](?!\d)/u',' ',$text);
    $text=molavi_domain_normalize_name(molavi_domain_identity_deglue($text));
    $tokens=preg_split('/\s+/u',trim($text),-1,PREG_SPLIT_NO_EMPTY)?:[];
    $units=['گرم','گرمی','گ','کیلوگرم','کیلویی','کیلو','میلی‌گرم','میلیگرم','میل','مل','لیتر','لیتری','ل','سی','cc','ml','mg','kg','g'];
    $packWords=['عددی','عدد','ع','تایی','تا','بسته','کارتن','باکس','قوطی','تکه'];
    $variantWords=['طعم','مزه','رنگ','مدل','نوع'];
    $stop=['و','با','در','از','موجود','مختلف','متنوع','قدیم'];
    $out=[];
    foreach($tokens as $token){
        if($token==='ف'||$token==='f'||$token==='r'||preg_match('/^r\d+$/D',$token))continue;
        if(in_array($token,$stop,true)||in_array($token,$units,true)
            ||in_array($token,$packWords,true)||in_array($token,$variantWords,true))continue;
        // A bare number is a size, a pack count, or a price code — never
        // product identity. Its following unit/pack word (if any) is
        // dropped by its own rule above.
        if(preg_match('/^\d+(?:[\/.٫]\d+)?$/D',$token))continue;
        if(!in_array($token,$out,true))$out[]=$token;
    }
    foreach($formats as $format)if(!in_array($format,$out,true))$out[]=$format;
    $aliases=molavi_domain_identity_brand_aliases();
    foreach($out as &$token)if(isset($aliases[$token]))$token=$aliases[$token];
    unset($token);
    return ['tokens'=>array_values(array_unique($out)),'stale'=>$stale];
}
/** Split compound tokens against a lexicon of standalone tokens
 * (snapshot titles + the other side's tokens). A token splits only
 * when every part is a known standalone token, parts >= 2 chars. */
function molavi_domain_identity_split_token(string $token,array $lexicon): array {
    $len=function_exists('mb_strlen')?mb_strlen($token,'UTF-8'):strlen($token);
    if($len<4)return [$token];
    for($i=2;$i<=$len-2;$i++){
        $a=function_exists('mb_substr')?mb_substr($token,0,$i,'UTF-8'):substr($token,0,$i);
        $b=function_exists('mb_substr')?mb_substr($token,$i,null,'UTF-8'):substr($token,$i);
        if(isset($lexicon[$a])&&isset($lexicon[$b]))
            return array_merge(molavi_domain_identity_split_token($a,$lexicon),
                molavi_domain_identity_split_token($b,$lexicon));
    }
    return [$token];
}
function molavi_domain_identity_split_tokens(array $tokens,array $lexicon): array {
    $out=[];
    foreach($tokens as $token)foreach(molavi_domain_identity_split_token($token,$lexicon) as $part)
        if(!in_array($part,$out,true))$out[]=$part;
    return $out;
}
/** Every word of a lexicon built from the current snapshot's standalone
 * title tokens. Set by molavi_domain_matching_index(); read by the
 * candidate loop so the other side's tokens can extend it. */
function molavi_domain_identity_lexicon(?array $lexicon=null): array {
    static $stored=[];
    if($lexicon!==null)$stored=$lexicon;
    return $stored;
}
/** True when every word of $brand is a token of the (split) catalog
 * title — or, for words >= 4 chars, a substring of a token — so a
 * brand need not be space-delimited to be found. A brand word
 * immediately preceded by طرح/سبک/شبیه is a lookalike mention, not
 * the brand, and does not count. */
function molavi_domain_identity_brand_in_tokens(string $brand,array $tokens): bool {
    $words=preg_split('/\s+/u',trim(molavi_domain_normalize_name($brand)),-1,PREG_SPLIT_NO_EMPTY)?:[];
    if(!$words)return false;
    $usable=[];
    foreach($tokens as $i=>$token){
        if($i>0&&in_array($tokens[$i-1],['طرح','سبک','شبیه'],true))continue;
        $usable[]=$token;
    }
    foreach($words as $word){
        $len=function_exists('mb_strlen')?mb_strlen($word,'UTF-8'):strlen($word);
        $found=false;
        foreach($usable as $token){
            if($token===$word||($len>=4&&str_contains($token,$word))){$found=true;break;}
        }
        if(!$found)return false;
    }
    return true;
}
/** True when every word of $text occurs in $tokens. */
function molavi_domain_identity_words_in_tokens(string $text,array $tokens): bool {
    $words=preg_split('/\s+/u',trim(molavi_domain_normalize_name($text)),-1,PREG_SPLIT_NO_EMPTY)?:[];
    foreach($words as $word)if(!in_array($word,$tokens,true))return false;
    return (bool)$words;
}
/** Full-identity agreement between an incoming product (as returned by
 * molavi_domain_match_values) and one catalog reference, given the
 * catalog row's SPLIT content tokens and its stale flag.
 * $requireBrand=true (backfill tier 0): the supplier brand must be
 * present AND positively agreed — a missing supplier brand never
 * auto-matches, which is what keeps a no-brand tuna off a random
 * other brand's tuna. $requireBrand=false (extraction junction): a
 * missing supplier brand is neutral, but a filled supplier brand
 * still needs the catalog brand to agree or be inferable from the
 * title — a missing catalog brand must NOT veto when inferable.
 * Size/pack: agreement when both sides provide the fact; missing on
 * one side is unknown, never a mismatch. Category is NEVER compared
 * here and never vetoes anywhere. Tokens: containment is measured on
 * the SUPPLIER side, strictly above 0.80. $supplierRawName is the
 * un-normalised supplier name: the (1*N) format code and any brand
 * spelling live there (match_name already destroyed the `*`). */
function molavi_domain_identity_agreement(array $incoming,array $reference,array $catalogTokens,bool $stale,array $lexicon,bool $requireBrand,?string $supplierRawName=null): array {
    $supplierTokens=molavi_domain_identity_split_tokens(array_values(array_unique(array_merge(
        molavi_domain_identity_parse_title($supplierRawName??(string)molavi_domain_py_value($incoming['name']??''))['tokens'],
        molavi_domain_identity_parse_title((string)molavi_domain_py_value($incoming['variant']??''))['tokens'],
        molavi_domain_identity_parse_title((string)molavi_domain_py_value($incoming['brand']??''))['tokens'],
    ))),$lexicon);
    $brand=molavi_domain_identity_alias_brand((string)molavi_domain_py_value($incoming['brand']??''));
    $catalogBrand=molavi_domain_identity_alias_brand((string)molavi_domain_py_value($reference['brand']??''));
    if($brand===''){$brandAgree=!$requireBrand;$brandState='supplier_empty';}
    elseif($catalogBrand!==''){$brandAgree=$catalogBrand===$brand;$brandState=$brandAgree?'agree':'conflict';}
    elseif(molavi_domain_identity_brand_in_tokens($brand,$catalogTokens)){$brandAgree=true;$brandState='inferred_from_title';}
    else{$brandAgree=false;$brandState='catalog_unknown';}
    /* Brand words are judged once, by brand_state: a brand that agrees
     * via the column must not also penalise token containment just
     * because the catalog title does not spell it out (e.g. a row whose
     * brand column is filled but whose title is just the product name).
     * The inference above runs on the untouched tokens first. */
    $brandWords=molavi_domain_identity_split_tokens(array_values(array_unique(array_merge(
        molavi_domain_identity_parse_title($brand)['tokens'],
        molavi_domain_identity_parse_title($catalogBrand)['tokens'],
    ))),$lexicon);
    $supplierProduct=array_values(array_diff($supplierTokens,$brandWords));
    $catalogProduct=array_values(array_diff($catalogTokens,$brandWords));
    // A name that is only the brand plus size/pack words (بلک ولف 1 لیتر)
    // has no product tokens left after the removal — the brand words ARE
    // the product identity there, so fall back to the un-removed sets.
    if($supplierProduct){$supplierTokens=$supplierProduct;$catalogTokens=$catalogProduct;}
    $common=count(array_intersect($supplierTokens,$catalogTokens));
    // Containment is measured on the SUPPLIER side, STRICTLY above 0.80:
    // a supplier word the catalog lacks (زیتون vs plain, فلفلی vs مرغ)
    // is a real difference, not underspecification. The catalog may carry
    // extra words the supplier omitted (بزرگ/کوچک, a flavour) — that is
    // ambiguity for uniqueness/price to resolve, not a mismatch.
    $overlap=count($supplierTokens)?$common/count($supplierTokens):0.0;
    $tokenAgree=count($supplierTokens)>0&&$overlap>0.80;
    $variant=molavi_domain_normalize_name((string)molavi_domain_py_value($incoming['variant']??''));
    if(preg_match('/^(?:در )?\d+ (?:طعم|مزه|رنگ|مدل|نوع)(?: (?:موجود|مختلف|متنوع))?$/uD',$variant))$variant='';
    $catalogVariant=molavi_domain_normalize_name((string)molavi_domain_py_value($reference['variant']??''));
    if($variant==='')$variantAgree=true;
    elseif($catalogVariant!=='')$variantAgree=$variant===$catalogVariant;
    else$variantAgree=molavi_domain_identity_words_in_tokens($variant,$catalogTokens);
    $sizeAgree=true;
    foreach(['volume','weight'] as $kind){
        $left=molavi_domain_reference_measurement_signature((string)molavi_domain_py_value($incoming[$kind]??''),$kind);
        $right=molavi_domain_reference_measurement_signature(
            (string)molavi_domain_py_value($reference['volume']??'').' '.(string)molavi_domain_py_value($reference['weight']??''),$kind);
        if($left&&$right){sort($left);sort($right);if($left!==$right)$sizeAgree=false;}
    }
    $supplierPack=molavi_domain_match_number($incoming['package_count']??null);
    $catalogPack=molavi_domain_match_number($reference['package_count']??null);
    $packAgree=!$supplierPack||!$catalogPack||$supplierPack==$catalogPack;
    $identity=$brandAgree&&$tokenAgree&&$variantAgree&&$sizeAgree&&$packAgree;
    $reason=!$brandAgree?'brand_'.$brandState:(!$tokenAgree?'token_overlap_low':(!$variantAgree?'variant_conflict':(!$sizeAgree?'size_conflict':(!$packAgree?'pack_conflict':'full_identity'))));
    return ['identity_match'=>$identity,'brand_agree'=>$brandAgree,'brand_state'=>$brandState,
        'token_overlap'=>round($overlap,4,PHP_ROUND_HALF_EVEN),'token_agree'=>$tokenAgree,
        'variant_agree'=>$variantAgree,'size_agree'=>$sizeAgree,'pack_agree'=>$packAgree,
        'stale_packaging'=>$stale,'reason'=>$reason];
}
function molavi_domain_matching_index(array $rows): array {
    // One request-scoped snapshot, shared by every card in this response.
    // Catalog rows are 21 columns (schema v1) or 26 (schema v2, two-price
    // model). Columns [0]-[20] keep their meanings; [21]-[25] (supply /
    // regular prices, anomaly flag) are ignored by matching.
    static $lastRows=null,$prepared=[];if($lastRows!==null&&$rows===$lastRows)return $prepared;
    $lastRows=$rows;$prepared=[];$lexicon=[];$staged=[];
    foreach($rows as $row){
        if(!is_array($row)||(count($row)!==21&&count($row)!==26))continue;
        $reference=molavi_domain_matching_reference($row);
        if($reference['reference_id']==='')continue;
        $values=molavi_domain_match_values($reference);
        // The reference side uses the structured count. Title inference is for supplier offers.
        $values['package_count']=$reference['package_count']?molavi_domain_cell_text($reference['package_count']):'';
        $title=molavi_domain_normalize_name(preg_replace('/[（(][^）)]*[）)]/u',' ',$reference['name']));
        // Pass 1: unsplit content tokens (variant-col words included) feed
        // the snapshot lexicon; splitting happens at comparison time so
        // the other side's tokens can supply compound parts too.
        $identity=molavi_domain_identity_parse_title($reference['name']);
        foreach(molavi_domain_identity_parse_title((string)molavi_domain_py_value($reference['variant']??''))['tokens'] as $token)
            if(!in_array($token,$identity['tokens'],true))$identity['tokens'][]=$token;
        foreach($identity['tokens'] as $token)$lexicon[$token]=true;
        $staged[]=[$reference,$values,' '.$title.' ',$identity];
    }
    molavi_domain_identity_lexicon($lexicon);
    $prepared=$staged;
    return $prepared;
}
function molavi_domain_reference_candidates(array $product,array $rows,int $limit=10): array {
    $weights=['name'=>.45,'brand'=>.14,'category'=>.12,'variant'=>.10,'package_count'=>.07,'volume'=>.06,'weight'=>.06];$incoming=molavi_domain_match_values($product);if($incoming['name']==='')return [];
    if(preg_match('/^(?:در )?\d+ (?:طعم|مزه|رنگ|مدل|نوع)(?: (?:موجود|مختلف|متنوع))?$/uD',$incoming['variant']))$incoming['variant']='';
    $sources=[];foreach(['name','brand','category','variant','volume','weight'] as $key){$value=$product[$key]??null;if($key==='name')$value=molavi_domain_py_value($product['clean_name']??null,$value);
        if($key==='variant')$value=molavi_domain_py_value($value,$product['variation_type']??null);$sources[$key]=trim((string)molavi_domain_py_value($value));}$sources['package_count']=$incoming['package_count'];
    $labels=['name'=>'نام','brand'=>'برند','category'=>'دسته','variant'=>'طعم/مدل','package_count'=>'تعداد در بسته','volume'=>'حجم','weight'=>'وزن'];$scored=[];
    $brand=$incoming['brand'];$brandInSupplierTitle=$brand!==''&&str_contains(' '.$incoming['name'].' ',' '.$brand.' ');
    // Known extraction typos are aliased to their brand before any
    // comparison (تمیز → تیمز); an unlisted misspelling never matches.
    $incoming['brand']=molavi_domain_identity_alias_brand($incoming['brand']);$brand=$incoming['brand'];
    $rawName=(string)molavi_domain_py_value($product['clean_name']??null,$product['name']??'');
    $identityMatches=[];
    $lexicon=molavi_domain_identity_lexicon();
    foreach(molavi_domain_identity_parse_title($rawName)['tokens'] as $token)$lexicon[$token]=true;
    foreach(molavi_domain_matching_index($rows) as [$reference,$right,$catalogTitle,$catalogIdentity]){
        $catalogIdentity=is_array($catalogIdentity)?$catalogIdentity:['tokens'=>[],'stale'=>false];
        $catalogTokens=molavi_domain_identity_split_tokens($catalogIdentity['tokens'],$lexicon);
        // Full-identity agreement (iteration 1): brand + tokens + variant
        // + size + pack. The string score computed below is unchanged.
        $agreement=molavi_domain_identity_agreement($incoming,$reference,$catalogTokens,(bool)($catalogIdentity['stale']??false),$lexicon,true,$rawName);
        if($brand!==''&&$right['brand']==='') {
            if(molavi_domain_identity_brand_in_tokens($brand,$catalogTokens)) {
                $right['brand']=$brand;
                $reference['brand']=$brand;
            }
        }
        $sameBrand=$brand!==''&&$right['brand']===$brand;
        if($sameBrand&&$incoming['variant']!==''&&$right['variant']===''
            &&molavi_domain_identity_words_in_tokens($incoming['variant'],$catalogTokens)) {
            $right['variant']=$incoming['variant'];$reference['variant']=$sources['variant'];
        }
        $components=[];$weighted=0.0;$denominator=0.0;
        foreach($weights as $key=>$weight){$left=$incoming[$key];if($left==='')continue;$denominator+=$weight;$score=$right[$key]!==''?molavi_domain_match_ratio($left,$right[$key]):0.0;
            $components[$key]=round($score,4,PHP_ROUND_HALF_EVEN);$weighted+=$weight*$score;}
        $score=$denominator?$weighted/$denominator:0.0;
        /* When the supplier brand is known, a row that is not that brand
         * needs strong name evidence to be shown at all (iteration 1):
         * a طرح/سبک mention or a matching pack count alone must not
         * surface a different-brand row. When the brand is unknown the
         * old 0.35 floor stays, so the string tiers keep their path. */
        $floor=(!$sameBrand && $brand!=='')?0.5:0.35;
        if(!$sameBrand&&($score<$floor||($components['name']??0)<$floor))continue;
        $comparisons=[];
        foreach($labels as $key=>$label){$source=$sources[$key];$normalized=$incoming[$key];$value=trim(molavi_domain_cell_text(molavi_domain_py_value($reference[$key]??null)));
            if($normalized==='')$state='not_provided';elseif($value==='')$state='reference_missing';
            elseif($key==='name'){$similarity=$components[$key]??0;$state=$similarity>=.92?'match':($similarity>=.72?'close':'conflict');}
            elseif($key==='package_count')$state=molavi_domain_match_number($normalized)===molavi_domain_match_number($value)?'match':'conflict';
            elseif(in_array($key,['volume','weight'],true))$state=$normalized===molavi_domain_match_name($value)?'match':'conflict';
            else $state=$normalized===molavi_domain_normalize_name($value)?'match':'conflict';
            $comparisons[]=['key'=>$key,'label'=>$label,'source'=>$source,'reference'=>$value,'state'=>$state];}
        $candidate=$reference+['score'=>round($score,4,PHP_ROUND_HALF_EVEN),'component_scores'=>$components,'name_similarity'=>round($components['name']??0,4,PHP_ROUND_HALF_EVEN),'comparisons'=>$comparisons];
        $supplier=molavi_domain_match_number(molavi_domain_py_value($product['supplier_price']??null,molavi_domain_py_value($product['detected_price']??null,molavi_domain_py_value($product['sale_price']??null,$product['price']??null))));
        if($supplier){$candidate['supplier_price']=$supplier;$candidate['price_role_hint']=molavi_domain_reference_role_hint($supplier,$reference);}
        $candidate['identity_match']=$agreement['identity_match'];
        $candidate['identity']=['token_overlap'=>$agreement['token_overlap'],'brand_state'=>$agreement['brand_state'],'reason'=>$agreement['reason']];
        $candidate['stale_packaging']=$agreement['stale_packaging'];
        if($sameBrand) $identityMatches[]=$candidate;else $scored[]=$candidate;
    }
    // The same-brand gate is a ranking bonus now, not a filter
    // (iteration 1): full-identity matches first, then same-brand, then
    // score — every brand stays visible to the owner and to backfill.
    $ranked=array_merge($identityMatches,$scored);
    $sameMap=[];foreach($identityMatches as $candidate)$sameMap[(string)$candidate['reference_id']]=true;
    $count=$identityMatches&&$brandInSupplierTitle&&!($incoming['variant']||$incoming['volume']||$incoming['weight'])
        ? molavi_domain_match_number($incoming['package_count']) : 0;
    usort($ranked,static function(array $a,array $b) use($count,$sameMap): int {
        $identityOrder=((!empty($b['identity_match']))<=>((!empty($a['identity_match']))));
        if($identityOrder)return $identityOrder;
        $brandOrder=(($sameMap[(string)$b['reference_id']]??false)<=>($sameMap[(string)$a['reference_id']]??false));
        if($brandOrder)return $brandOrder;
        $packOrder=$count ? (($b['package_count']===$count)<=>($a['package_count']===$count)) : 0;
        return $packOrder?:($b['score']<=>$a['score'])?:strcmp($a['reference_id'],$b['reference_id']);
    });
    return array_slice($ranked,0,max(1,min($limit,20)));
}
function molavi_domain_reference_role_hint($advertised,array $reference): array {
    $price=molavi_domain_match_number($advertised);$unit=molavi_domain_match_number($reference['unit_price']??null);$package=molavi_domain_match_number($reference['package_price']??null);
    if(!$price||!$unit||!$package||$unit===$package)return ['role'=>'needs_review','reason'=>'insufficient_reference'];
    $unitDistance=abs($price-$unit)/$unit;$packageDistance=abs($price-$package)/$package;
    if(min($unitDistance,$packageDistance)>.35||abs($unitDistance-$packageDistance)<.15)return ['role'=>'needs_review','reason'=>'ambiguous_or_distant'];
    return ['role'=>$unitDistance<$packageDistance?'unit':'package','reason'=>'reference_scale_hint','supplier_price'=>$price,'unit_distance'=>$unitDistance,'package_distance'=>$packageDistance];
}
/** catalog.measurements.measurement_signature: base-unit values as reduced fraction strings. */
function molavi_domain_reference_measurement_signature(string $text,string $kind): array {
    $out=[];
    foreach(molavi_domain_measurement_matches($text,$kind) as $match){
        $parts=explode(' ',$match['display'],2);
        // The display's "/" is always a fraction (from _format_fraction); the
        // source-text "/" is a decimal separator, so molavi_domain_fraction
        // would misread "1/3" as 1.3. Parse a display slash as a fraction.
        if(str_contains($parts[0],'/')){$slash=explode('/',$parts[0],2);$fraction=[(int)$slash[0],(int)$slash[1]];}
        else $fraction=molavi_domain_fraction($parts[0]);
        if($fraction===null)continue;[$n,$d]=$fraction;$label=$parts[1]??'';
        if($label==='لیتر'||$label==='کیلوگرم')$n*=1000;elseif($label==='میلی‌گرم')$d*=1000;
        $a=$n;$b=$d;while($b){$next=$a%$b;$a=$b;$b=$next;}
        // Python compares set(measurement_signature(...)): same base value
        // collapses, so dedupe by the reduced base string (not by display).
        $sig=(string)intdiv($n,$a).($d/$a!==1?'/'.(string)intdiv($d,$a):'');
        if(!in_array($sig,$out,true))$out[]=$sig;
    }
    return $out;
}
/** reference_import/matching.py candidate_match: full-identity equality
 * (iteration 1) instead of byte-identical names. A size-token difference
 * alone no longer blocks it; brand/variant/size/pack still agree by the
 * same rules as backfill tier 0, except a missing supplier brand is
 * neutral here (most offers carry no brand) — a filled supplier brand
 * still needs the catalog brand to agree or be inferable from the
 * title, and a catalog count of 0 is unknown, not a mismatch. A stale
 * (قدیم) row never returns 'candidate': it needs a human confirmation.
 * The 'exact_compatible_identity' reason is kept: the kept-match choke
 * point keys on it. */
function molavi_domain_reference_candidate_match(array $product,array $rows,array $overrides=[]): array {
    $name=molavi_domain_match_name(molavi_domain_py_value($product['clean_name']??null,$product['name']??''));
    if($name==='')return ['state'=>'needs_review','reason'=>'missing_name','candidates'=>[]];
    $incoming=molavi_domain_match_values($product);
    // Lexicon for compound-splitting: standalone tokens from the passed
    // rows plus the incoming product's own tokens.
    $lexicon=[];$rowIdentities=[];
    foreach($rows as $row){
        if(!is_array($row)||(count($row)!==21&&count($row)!==26))return ['state'=>'needs_review','reason'=>'invalid_reference_rows','candidates'=>[]];
        $identity=molavi_domain_identity_parse_title((string)molavi_domain_py_value($row[0]??''));
        foreach(molavi_domain_identity_parse_title((string)molavi_domain_py_value($row[11]??''))['tokens'] as $token)
            if(!in_array($token,$identity['tokens'],true))$identity['tokens'][]=$token;
        foreach($identity['tokens'] as $token)$lexicon[$token]=true;
        $rowIdentities[]=$identity;
    }
    foreach(molavi_domain_identity_parse_title($incoming['name'])['tokens'] as $token)$lexicon[$token]=true;
    $candidates=[];$staleCandidates=[];
    foreach($rows as $index=>$row){
        $reference=molavi_domain_matching_reference($row);
        $identity=$rowIdentities[$index];
        $catalogTokens=molavi_domain_identity_split_tokens($identity['tokens'],$lexicon);
        $agreement=molavi_domain_identity_agreement($incoming,$reference,$catalogTokens,(bool)($identity['stale']??false),$lexicon,false,
            (string)molavi_domain_py_value($product['clean_name']??null,$product['name']??''));
        if(!$agreement['identity_match'])continue;
        if($agreement['stale_packaging'])$staleCandidates[]=molavi_domain_matching_reference($row);
        else$candidates[]=molavi_domain_matching_reference($row);
    }
    $pinned=$overrides[molavi_domain_reference_signature($product)]??null;
    if(is_array($pinned)){
        $wanted=(string)molavi_domain_py_value($pinned['reference_id']??null);$selected=null;
        foreach($rows as $row){
            if(!is_array($row)||(count($row)!==21&&count($row)!==26))continue;
            $candidate=molavi_domain_matching_reference($row);
            if($candidate['reference_id']===$wanted){$selected=$candidate;break;}
        }
        if($selected!==null){
            $selected['owner_price_role']=(string)molavi_domain_py_value($pinned['price_role']??null);
            $selected['owner_decision_id']=(string)molavi_domain_py_value($pinned['decision_id']??null);
            return ['state'=>'candidate','reason'=>'owner_approved_mapping','candidates'=>[$selected]];
        }
    }
    if(!$candidates&&$staleCandidates)return ['state'=>'needs_review','reason'=>'stale_packaging_reference','candidates'=>$staleCandidates];
    if(!$candidates)return ['state'=>'needs_review','reason'=>'no_compatible_reference','candidates'=>[]];
    if(count($candidates)>1)return ['state'=>'needs_review','reason'=>'multiple_candidates','candidates'=>$candidates];
    if($candidates[0]['reference_id']==='')return ['state'=>'needs_review','reason'=>'missing_reference_identity','candidates'=>$candidates];
    return ['state'=>'candidate','reason'=>'exact_compatible_identity','candidates'=>$candidates];
}
