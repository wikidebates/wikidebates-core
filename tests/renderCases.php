<?php

$bibliography = 'Ada⟬Article⟬Book⟬1⟬2⟬Chapter⟬3⟬Publisher⟬Paris⟬2026⟬https://example.test/book⟬dead_link⟭';
$webliography = 'Page⟬Site⟬https://example.test/page?a=b⟬Author⟬2026⟬reference_incomplete⟭';
$videography = 'Video⟬https://example.test/video⟬Director⟬dead_link⟭';
$cases = [];
foreach (['en', 'fr'] as $lang) {
	$cases['empty debate ' . $lang] = '{{#debate:render|lang=' . $lang . '}}';
	$cases['empty argument ' . $lang] = '{{#argument:render|lang=' . $lang . '}}';
	$cases['complete debate ' . $lang] = '{{#debate:render|lang=' . $lang
		. '|topic=Test é & topic|expanded-topic=Expanded topic|progress=Draft'
		. '|title-warnings=title_non_standard|debate-warnings=debate_sensitive'
		. '|introduction=Intro⟬⟬⟬Text with [[Link|label]]⟬⟬⟬too_long⟭⟭⟭'
		. '|pro-arguments=Page one⟬Displayed one⟬displayed_title_unclear⟭Page two⟬⟬⟭'
		. '|con-arguments=Page three⟬Displayed three⟬⟭'
		. '|pro-bibliography=' . $bibliography . '|bibliography=' . $bibliography
		. '|con-webliography=' . $webliography . '|webliography=' . $webliography
		. '|pro-videography=' . $videography . '|videography=' . $videography
		. '|keywords=one, é, 0|sections=Science, Society|related-debates=Other debate, Third debate'
		. '|wikipedia-articles=Wikipedia:Example|interlanguage=[[fr:Exemple]]|creation-date=2026-01-01}}';
	$cases['complete argument ' . $lang] = '{{#argument:render|lang=' . $lang
		. '|summary=Text with [[Link|label]], é and {{!}} signs|summary-warnings=summary_unclear'
		. '|title-warnings=title_unclear|argument-warnings=argument_sensitive'
		. '|quotes=Quote⟬⟬⟬Author⟬⟬⟬Article⟬⟬⟬Book⟬⟬⟬1⟬⟬⟬2⟬⟬⟬Chapter⟬⟬⟬3⟬⟬⟬Publisher⟬⟬⟬Paris⟬⟬⟬2026⟬⟬⟬https://example.test⟬⟬⟬quote_translated⟭⟭⟭'
		. '|bibliography=' . $bibliography . '|webliography=' . $webliography . '|videography=' . $videography
		. '|justifications=First⟬Label⟬displayed_title_unclear⟭Second⟬⟬⟭'
		. '|objections=Third⟬Third label⟬⟭|justification-warnings=justifications_to_reorganize'
		. '|objection-warnings=objections_to_reorganize|keywords=one, é, 0|sections=Science'
		. '|dedicated-debate=Main debate|creation-date=2026-01-01}}';
}
$cases['French debate template'] = '{{Débat|sujet=Essai|introduction=Introduction|arguments-pour=Page⟬Titre⟬⟭}}';
$cases['French argument template'] = '{{Argument|résumé=Résumé|objections=Page⟬Titre⟬⟭}}';
$cases['escaping and parameter boundaries'] = '{{#debate:render|topic=A=B {{!}} C & "D"|keywords=0, {{!}}, é|introduction=</script><script>unsafe</script>}}';
$cases['duplicate arguments and zero'] = '{{#argument:render|summary=old|summary=0|keywords=0|objections=0⟬0⟬0⟭}}';
return $cases;
