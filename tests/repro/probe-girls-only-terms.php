<?php
// Read-only probe: list pa_girls-only terms with WPML language + variation usage.
global $wpdb;
$p = $wpdb->prefix;
echo "prefix=$p\n";
foreach (intersoccer_get_girls_only_attribute_taxonomies() as $tax) {
  echo "tax $tax exists=".(taxonomy_exists($tax)?1:0)."\n";
}
$rows = $wpdb->get_results("SELECT t.term_id,t.slug,t.name,tt.taxonomy,tt.count,tt.term_taxonomy_id, icl.language_code, icl.trid FROM {$p}terms t JOIN {$p}term_taxonomy tt ON tt.term_id=t.term_id LEFT JOIN {$p}icl_translations icl ON icl.element_id=tt.term_taxonomy_id AND icl.element_type=CONCAT('tax_',tt.taxonomy) WHERE tt.taxonomy LIKE 'pa_girl%' ORDER BY icl.trid, icl.language_code");
foreach ($rows as $r) echo json_encode($r, JSON_UNESCAPED_UNICODE),"\n";
$vals = $wpdb->get_results("SELECT meta_value, COUNT(*) c FROM {$p}postmeta WHERE meta_key LIKE 'attribute_pa_girl%' GROUP BY meta_value");
foreach ($vals as $v) echo "variation meta value: ".json_encode($v->meta_value, JSON_UNESCAPED_UNICODE)." x{$v->c}\n";
foreach ([54216=>54231, 51723=>51724, 51732=>51758] as $pid=>$vid) {
  echo "$pid/$vid lang=".(apply_filters('wpml_post_language_details',null,$pid)['language_code']??'?')." pairs=".json_encode(intersoccer_collect_taxonomy_term_pairs_for_line($pid,$vid,'pa_girls-only'), JSON_UNESCAPED_UNICODE)." girls_only=".var_export(intersoccer_line_is_girls_only_program($pid,$vid),true)."\n";
}
foreach (['en','fr','de'] as $lang) {
  do_action('wpml_switch_language', $lang);
  foreach ([54216=>54231, 51723=>51724, 51732=>51758] as $pid=>$vid) {
    echo "[$lang] $pid/$vid pairs=".json_encode(intersoccer_collect_taxonomy_term_pairs_for_line($pid,$vid,'pa_girls-only'), JSON_UNESCAPED_UNICODE)." act=".json_encode(intersoccer_collect_activity_type_terms_for_line($pid,$vid), JSON_UNESCAPED_UNICODE)." girls_only=".var_export(intersoccer_line_is_girls_only_program($pid,$vid),true)."\n";
  }
}
