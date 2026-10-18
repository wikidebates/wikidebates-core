return {
	Common = {
		labels = {
			edit_label = { fr = "Modifier", en = "Edit" },

			site_title_text = {
				fr = "Wikidébats, l’encyclopédie des arguments « pour » et « contre »",
				en = "Wikidebates, the encyclopedia of “pro” and “con” arguments",
			},

			arg_pro_label = { fr = "Argument pour", en = "Pro argument" },
			arg_con_label = { fr = "Argument contre", en = "Con argument" },
			justification_label = { fr = "Justification", en = "Justification" },
			objection_label = { fr = "Objection", en = "Objection" },
			arguments_label = { fr = "Arguments", en = "Arguments" },
			justifications_label = { fr = "Justifications", en = "Justifications" },
			objections_label = { fr = "Objections", en = "Objections" },

			none_label = { fr = "aucun", en = "none" },

			debates_label = { fr = "Débats", en = "Debates" },

			argument_map_label = { fr = "Carte des arguments", en = "Argument map" },

			rename_page_label = { fr = "Renommer la page", en = "Rename page" },
		},

		props = {
			keyword = { fr = "Mot-clé", en = "Keyword" },
			work_name = { fr = "Nom d'ouvrage", en = "Work name" },
			publishing_house = { fr = "Maison d'édition", en = "Publishing house" },
			place_of_publication = { fr = "Lieu d'édition", en = "Place of publication" },
			author = { fr = "Auteur", en = "Author" },
			article_name = { fr = "Nom d'article", en = "Article name" },
			website_name = { fr = "Nom de site Internet", en = "Website name" },
		},

		forms = {
			search_by_keywords_title = { fr = "Recherche par mots-clés", en = "Search by keywords" },
		},

		params = {
			keywords_field = { fr = "mots-clés", en = "keywords" },
			type_field = { fr = "type", en = "type" },
		},

		values = {
			type_arguments_value = "@Common.labels.arguments_label",
		},

		text = {
			keywords_label_text = { fr = "<b>Mots-clés</b> : ", en = "<b>Keywords</b>: " },
			rename_link_label = { fr = "renommer", en = "rename" },
			list_conjunction_text = { fr = " et ", en = " and " },
		},
	},

	Debate = {
		forms = {
			intro_edit_title = { fr = "Pour comprendre le débat", en = "To understand the debate" },
			wiki_seealso_edit_title = { fr = "Voir aussi sur Wikipédia", en = "See also on Wikipedia" },
			args_pro_edit_title = { fr = "Arguments pour", en = "Pro arguments" },
			args_con_edit_title = { fr = "Arguments contre", en = "Con arguments" },

			new_arg_title = { fr = "Nouveau titre d'argument", en = "New argument title" },
			new_arg_pro_type_label = "@Common.labels.arg_pro_label",
			new_arg_con_type_label = "@Common.labels.arg_con_label",

			self_eval_home_title = { fr = "Autoévaluation (accueil)", en = "Self-evaluation (home)" },
			keywords_edit_title = { fr = "Mots-clés de débat", en = "Debate keywords" },
			related_edit_title = { fr = "Débats connexes", en = "Related debates" },
			topic_edit_title = "@Debate.props.debate_topic",
			sections_edit_title = { fr = "Rubriques de débat", en = "Debate sections" },
			interlanguage_edit_title = { fr = "Interlangue de débat", en = "Debate interlanguage" },
			banners_edit_title = { fr = "Bandeaux de débat", en = "Debate banners" },

			biblio_pro_title = { fr = "Bibliographie pour", en = "Pro bibliography" },
			biblio_con_title = { fr = "Bibliographie contre", en = "Con bibliography" },
			biblio_neutral_title = { fr = "Bibliographie ni pour ni contre", en = "Bibliography" },

			webliography_pro_title = { fr = "Sitographie pour", en = "Pro webliography" },
			webliography_con_title = { fr = "Sitographie contre", en = "Con webliography" },
			webliography_neutral_title = { fr = "Sitographie ni pour ni contre", en = "Webliography" },

			videography_pro_title = { fr = "Vidéographie pour", en = "Pro videography" },
			videography_con_title = { fr = "Vidéographie contre", en = "Con videography" },
			videography_neutral_title = { fr = "Vidéographie ni pour ni contre", en = "Videography" },

			search_by_keywords_title = "@Common.forms.search_by_keywords_title",
		},

		params = {
			keywords_field = "@Common.params.keywords_field",
			search_type_field = "@Common.params.type_field",
			search_type_value = "@Debate.categories.debates",

			color_field = { fr = "couleur", en = "color" },
			icon_field = { fr = "icône", en = "icon" },
			title_field = { fr = "titre", en = "title" },
			text_field = { fr = "texte", en = "text" },

			content_field = { fr = "contenu", en = "content" },
			page_field = { fr = "page", en = "page" },

			--	Paramètres des modèles de références
			authors_field = { fr = "auteurs", en = "authors" },
			article_field = { fr = "article", en = "article" },
			work_field = { fr = "ouvrage", en = "work" },
			volume_field = { fr = "volume", en = "volume" },
			number_field = { fr = "numero", en = "number" },
			location_field = { fr = "localisation", en = "location" },
			publisher_field = { fr = "edition", en = "publisher" },
			place_field = { fr = "lieu", en = "place" },
			date_field = { fr = "date", en = "date" },
			link_field = { fr = "lien", en = "link" },
			warnings_field = { fr = "avertissements", en = "warnings" },
			site_field = { fr = "site", en = "site" },
		},

		progress = {
			draft = { fr = "Ébauche", en = "Draft" },
			building = { fr = "Débat en construction", en = "Debate under construction" },
			constructed = { fr = "Débat construit", en = "Constructed debate" },
		},

		categories = {
			debates = "@Common.labels.debates_label",
			progress_missing = { fr = "Débats sans niveau d'avancement", en = "Debates without progress level" },
			constructed = { fr = "Débats construits", en = "Constructed debates" },
			intro_missing = { fr = "Débats sans introduction", en = "Debates without introduction" },
			wikipedia_missing = { fr = "Débats sans article Wikipédia", en = "Debates without Wikipedia link" },
			pro_missing = { fr = "Débats sans argument pour", en = "Debates without pro argument" },
			con_missing = { fr = "Débats sans argument contre", en = "Debates without con argument" },
			keywords_missing = { fr = "Débats sans mot-clé", en = "Debates without keywords" },
			related_missing = { fr = "Débats sans débat connexe", en = "Debates without related debate" },
			sections_missing = { fr = "Débats sans rubrique", en = "Debates without section" },
			topic_missing = { fr = "Débats sans sujet", en = "Debates without topic" },
			topic_complete_missing = { fr = "Débats sans sujet développé", en = "Debates without expanded topic" },
			refs_to_review = { fr = "Références à revoir", en = "References to review" },
			displayed_titles_to_review = { fr = "Titres d'arguments affichés à revoir", en = "Argument displayed titles to review" },
			creation_date_missing = { fr = "Articles sans date de création", en = "Articles without creation date" },
		},

		text = {
			warnings_title = { fr = "Avertissements", en = "Warnings" },
			debate_banners_edit_tt = { fr = "Modifier les bandeaux d'avertissement du débat", en = "Edit the debate warning banners" },

			main_banner_color = "vert",
			main_banner_title = {
				fr = 'Cet article vise à exposer tous les arguments « pour » et « contre » du débat <span style="font-weight: normal;">« %s »</span>, pour permettre à chacun de se forger une opinion critique et éclairée.',
				en = 'This article aims to present all the pros and cons of the debate <span style="font-weight: normal;">“%s”</span>, to help everyone form a critical, informed opinion.',
			},

			learn_more_label = { fr = "En savoir plus : ", en = "Learn more: " },
			founding_principles_link = { fr = "[[Wikidébats:Principes fondateurs|Principes fondateurs]]", en = "[[Wikidebates:Founding Principles|Founding Principles]]" },

			understand_debate_title = "@Debate.forms.intro_edit_title",

			edit_label = "@Common.labels.edit_label",
			site_title_text = "@Common.labels.site_title_text",

			intro_edit_tt = { fr = "Modifier l'introduction du débat", en = "Edit the debate introduction" },
			none_intro_msg = { fr = "Aucune introduction n'a été entrée pour le moment.", en = "No introduction has been entered yet." },

			wikipedia_seealso_msg = { fr = "Voir aussi : %s sur Wikipédia", en = "See also: %s on Wikipedia" },
			none_wikipedia_msg = { fr = "Aucun lien vers une page Wikipédia n'a été entré pour le moment.", en = "No links to a Wikipedia page have been provided yet." },
			wikipedia_edit_tt = { fr = "Modifier les articles Wikipédia du bandeau", en = "Edit the Wikipedia articles in the banner" },

			args_pro_title = { fr = "Arguments « pour »", en = "Pros" },
			args_con_title = { fr = "Arguments « contre »", en = "Cons" },

			args_pro_edit_tt = { fr = "Modifier la liste des arguments « pour » ci-dessous", en = "Edit the list of pros below" },
			args_con_edit_tt = { fr = "Modifier la liste des arguments « contre » ci-dessous", en = "Edit the list of cons below" },

			faq_q_pro = { fr = "Quels sont les arguments pour %s ?", en = "What are the pros of %s?" },
			faq_q_con = { fr = "Quels sont les arguments contre %s ?", en = "What are the cons of %s?" },
			faq_q = { fr = "Quels sont les arguments pour et contre %s ?", en = "What are the pros and cons of %s?" },

			none_pro_args_msg = { fr = "Aucun argument « pour » n'a été entré pour le moment.", en = "No pros have been added yet." },
			none_con_args_msg = { fr = "Aucun argument « contre » n'a été entré pour le moment.", en = "No cons have been added yet." },

			args_add_label = { fr = "Ajouter un argument « %s »", en = "Add a “%s” argument" },

			arg_add_tt = { fr = "Ajouter un nouvel argument « %s » au débat : %s", en = "Add a new “%s” argument to the debate: %s" },

			side_pro_word = { fr = "pour", en = "pro" },
			side_con_word = { fr = "contre", en = "con" },

			further_reading_title = { fr = "Pour aller plus loin", en = "Further reading" },
			bibliography_title = { fr = "Bibliographie", en = "Bibliography" },
			webliography_title = { fr = "Sitographie", en = "Webliography" },
			videography_title = { fr = "Vidéographie", en = "Videography" },

			side_pro_title = { fr = "Côté « pour »", en = "“Pro” side" },
			side_con_title = { fr = "Côté « contre »", en = "“Con” side" },
			side_neutral_title = { fr = "Ni « pour » ni « contre »", en = "Neither “pro” nor “con”" },

			section_edit_tt = { fr = "Modifier cette rubrique", en = "Edit this section" },
			none_references_msg = { fr = "Aucune référence n'a été entrée pour le moment.", en = "No references have been entered yet." },

			keywords_label_text = "@Common.text.keywords_label_text",
			none_label = "@Common.labels.none_label",

			search_by_keyword_tt = { fr = "Rechercher les pages sur ce thème", en = "Search pages about this topic" },
			keywords_edit_tt = { fr = "Modifier les mots-clés thématiques décrivant ce débat", en = "Edit the thematic keywords describing this debate" },

			related_edit_tt = { fr = "Modifier la liste des débats connexes", en = "Edit the list of related debates" },
			none_related_msg = { fr = "Aucun débat connexe n'a été entré pour le moment.", en = "No related debates have been entered yet." },

			self_eval_title = { fr = "Testez-vous", en = "Self-assessment" },
			self_eval_blurb = {
				fr = "<p><b>Êtes-vous plutôt « pour » ou « contre » ? Autoévaluez-vous !</b></p><p>Vous pouvez parcourir le contenu de ce débat en répondant, pour chaque argument, à deux questions :</p><ol><li> Cet argument est-il bien fondé ?</li><li> Cet argument est-il important pour vous ?</li></ol><p>Vous obtiendrez votre pourcentage de « pour » ou de « contre » et une analyse de votre opinion.</p>",
				en = "<p><b>Are you more “pro” or “con”? Assess yourself!</b></p><p>You can browse this debate by answering, for each argument, two questions:</p><ol><li> Is this argument well-founded?</li><li> Is this argument important to you?</li></ol><p>You’ll get your “pro/con” percentage and an analysis of your stance.</p>",
			},
			self_eval_start_label = { fr = "Commencer", en = "Start" },
			self_eval_start_tt = { fr = "Commencer l'autoévaluation", en = "Start the self-assessment" },

			latest_changes_title = { fr = "Dernières contributions", en = "Recent contributions" },
			latest_changes_show_label = { fr = "Afficher les dernières contributions", en = "Show recent contributions" },
			latest_changes_show_tt = { fr = "Afficher les dernières contributions apportées à ce débat", en = "Show recent contributions to this debate" },

			feedback_title = { fr = "Vos retours", en = "Your feedback" },

			shortdesc_plain = { fr = "Arguments pour et contre", en = "Pros and cons" },
			shortdesc_topic = { fr = "Arguments pour et contre %s", en = "Pros and cons of %s" },

			topic_edit_tt = { fr = "Modifier le sujet du débat", en = "Edit the debate topic" },
			sections_edit_tt = { fr = "Modifier les rubriques du débat", en = "Edit the debate sections" },
			interlanguage_edit_tt = { fr = "Modifier les liens interlangues du débat", en = "Edit the interlanguage links for the debate" },

			arg_map_pro_label = { fr = "POUR", en = "PROS" },
			arg_map_con_label = { fr = "CONTRE", en = "CONS" },
			none_pro_args_msg_short = { fr = "Aucun argument « pour » n'a été entré.", en = "No pros have been added yet." },
			none_con_args_msg_short = { fr = "Aucun argument « contre » n'a été entré.", en = "No cons have been added yet." },

			rename_link_label = "@Common.text.rename_link_label",
			rename_special_page_text = { fr = "Spécial:Renommage/%s", en = "Special:MovePage/%s" },

			self_eval_ns_label = { fr = "Autoévaluation", en = "Self-evaluation" },
			self_eval_debate_field = { fr = "débat", en = "debate" },

			unknown_author = { fr = "Auteur non renseigné", en = "Author not provided", },

			default_subsection_title = {
				fr = "À savoir",
				en = "Good to know",
			},
			intro_empty_msg = {
				fr = "Aucun contenu n’a été entré.",
				en = "No content has been entered.",
			},
			intro_empty_link_label = {
				fr = "ici",
				en = "here",
			},
			intro_empty_category = {
				fr = "Introductions de débats vides",
				en = "Empty debate introductions",
			},
		},

		urls = {
			wikipedia = {
				fr = "https://fr.wikipedia.org/wiki/",
				en = "https://en.wikipedia.org/wiki/"
			},
			home = {
				fr = "https://fr.wikidebates.org/",
				en = "https://en.wikidebates.org/"
			},
			home_label = { fr = "Accueil", en = "Main Page" },
			wiki_base = {
				fr = "https://fr.wikidebates.org/wiki/",
				en = "https://en.wikidebates.org/wiki/"
			},
			discussion_prefix = {
				fr = "Discussion:",
				en = "Talk:"
			},
			category_prefix = {
				fr = "Catégorie:",
				en = "Category:"
			},
			portal_prefix = {
				fr = "Portail:",
				en = "Portal:"
			},
			property_prefix = {
				fr = "Attribut:",
				en = "Property:"
			},
			debates_label = { fr = "Débats", en = "Debates" },
			sections_label = { fr = "Rubriques", en = "Sections" },
			pro_label = { fr = "Argument_pour", en = "Pro_argument" },
			con_label = { fr = "Argument_contre", en = "Con_argument" },
		},

		seo = {
			title_separator = {
				fr = "–",
				en = "–"
			},
			description = {
				fr = "Arguments pour et contre %s : argumentaire pro et anti, défense et critiques, justifications et objections, preuves et réfutations.",
				en = "Arguments for and against %s: pros and cons, justifications and objections, proofs and refutations, defence and criticism.",
			},
			image = {
				fr = "https://fr.wikidebates.org/w/resources/assets/logo-Wikidebats-Twitter.png",
				en = "https://en.wikidebates.org/w/resources/assets/logo-Wikidebates-Twitter.png"
			},
			image_alt = {
				fr = "Balance de la raison qui pèse les arguments pour et contre",
				en = "Scales of reason weighing arguments for and against"
			},
			section = {
				fr = "Débat",
				en = "Debate"
			},
			site_name = {
				fr = "Wikidébats",
				en = "Wikidebates"
			},
			twitter_site = {
				fr = "@Wikidebats",
				en = "@Wikidebats"
			},
			locale = {
				fr = "fr_FR",
				en = "en_US"
			},
			author = {
				fr = "Contributeurs de Wikidébats",
				en = "Wikidebates contributors"
			},
		},

		props = {
			debate_name = { fr = "Nom de débat", en = "Debate name" },
			debate_number = { fr = "Numéro de débat", en = "Debate number" },
			parent_debate = { fr = "Débat parent", en = "Parent debate" },
			breadcrumb = { fr = "Fil d'Ariane", en = "Breadcrumb" },
			keyword = "@Common.props.keyword",
			arg_map = "@Common.labels.argument_map_label",
			debate_topic = { fr = "Sujet de débat", en = "Debate topic" },
			pro_list = { fr = "Liste des arguments pour", en = "Pro list" },
			con_list = { fr = "Liste des arguments contre", en = "Con list" },
			arg_pro = "@Common.labels.arg_pro_label",
			arg_con = "@Common.labels.arg_con_label",

			article_name = "@Common.props.article_name",
			work_name = "@Common.props.work_name",
			publishing_house = "@Common.props.publishing_house",
			place_of_publication = "@Common.props.place_of_publication",
			author = "@Common.props.author",
			author_pro = { fr = "Auteur pour", en = "Pro author" },
			author_con = { fr = "Auteur contre", en = "Con author" },
			website_name = "@Common.props.website_name",
		},

		----------------------------------------------------------------------
		--	BANNERS
		----------------------------------------------------------------------

		banners = {
			----------------------------------------------------------------------
			--	PROGRESS (2)
			----------------------------------------------------------------------

			progress_under_construction_label = "@Debate.progress.building",
			progress_under_construction_color = "bleu",
			progress_under_construction_icon = "wk-icon-construction",
			progress_under_construction_title = {
				fr = "Ce débat est [[:Category:Débats en construction|en construction]]. Des arguments manquent ou ne sont pas rédigés. L’introduction et la bibliographie demandent à être améliorées.",
				en = "This debate is [[:Category:Debates under construction|under construction]]. Arguments are missing or not written. The introduction and bibliography need improvement.",
			},
			progress_under_construction_text = { fr = "N'hésitez pas à les compléter.", en = "Feel free to complete them." },
			progress_under_construction_category = { fr = "Débats en construction", en = "Debates under construction" },

			progress_draft_label = { fr = "Ébauche", en = "Draft" },
			progress_draft_color = "bleu",
			progress_draft_icon = "wk-icon-draft",
			progress_draft_title = {
				fr = "Cette page est une [[:Category:Ébauches de débats|ébauche de débat]].",
				en = "This page is a [[:Category:Debate drafts|debate draft]]. This is a more or less organized list of arguments.",
			},
			progress_draft_text = {
				fr = "N'hésitez pas à l'[[Aide:Sommaire|améliorer]].",
				en = "Please feel free to contribute to this draft.",
			},
			progress_draft_category = { fr = "Ébauches de débats", en = "Debate drafts" },

			----------------------------------------------------------------------
			--	TITLE WARNINGS (3)
			----------------------------------------------------------------------

			title_non_standard_label = { fr = "Titre non standard", en = "Non-standard title" },
			title_non_standard_color = "orange",
			title_non_standard_icon = "wk-icon-title",
			title_non_standard_title = {
				fr = "Le titre de ce débat est [[:Category:Titres de débats non standard|non standard]]. Il n'est pas possible de répondre par « oui » ou par « non » à la question posée.",
				en = "The title of this debate is [[:Category:Non-standard debate titles|non-standard]]. The question posed does not have the possible answer “yes” or “no”.",
			},
			title_non_standard_text = {
				fr = "[[Special:MovePage/%s|Reformulez la question]].",
				en = "[[Special:MovePage/%s|Reframe the question]] as a pro/con debate.",
			},
			title_non_standard_category = { fr = "Titres de débats non standard", en = "Non-standard debate titles" },

			title_to_explain_label = { fr = "Titre à expliciter", en = "Title to be explained" },
			title_to_explain_color = "orange",
			title_to_explain_icon = "wk-icon-title",
			title_to_explain_title = {
				fr = "Le titre de ce débat est [[:Category:Titres de débats à expliciter|à expliciter]]. La question posée n’est pas claire ou est ambiguë.",
				en = "The title of this debate is [[:Category:Debate titles to be explained|to be explained]]. The question asked is unclear or ambiguous.",
			},
			title_to_explain_text = {
				fr = "[[Special:MovePage/%s|Trouvez un intitulé]] qui fasse ressortir clairement la problématique.",
				en = "[[Special:MovePage/%s|Find a title]] that clearly highlights the problem.",
			},
			title_to_explain_category = { fr = "Titres de débats à expliciter", en = "Debate titles to be explained" },

			title_to_simplify_label = { fr = "Titre à simplifier", en = "Title to simplify" },
			title_to_simplify_color = "orange",
			title_to_simplify_icon = "wk-icon-title",
			title_to_simplify_title = {
				fr = "Le titre de ce débat est [[:Category:Titres de débats à simplifier|à simplifier]]. Il est formulé de manière inutilement complexe.",
				en = "The title of this debate should be [[:Category:Debate titles to simplify|simplified]]. It is formulated in an unnecessarily complex way.",
			},
			title_to_simplify_text = {
				fr = "[[Special:MovePage/%s|Trouvez un intitulé]] clair et compact.",
				en = "[[Special:MovePage/%s|Find a clear and compact title]].",
			},
			title_to_simplify_category = { fr = "Titres de débats à simplifier", en = "Debate titles to simplify" },

			----------------------------------------------------------------------
			--	DEBATE WARNINGS (6)
			----------------------------------------------------------------------

			debate_unbalanced_label = { fr = "Débat déséquilibré", en = "Unbalanced debate" },
			debate_unbalanced_color = "orange",
			debate_unbalanced_icon = "wk-icon-unbalanced",
			debate_unbalanced_title = {
				fr = "Ce débat est [[:Category:Débats déséquilibrés|déséquilibré]].",
				en = "This debate is [[:Category:Unbalanced debates|unbalanced]].",
			},
			debate_unbalanced_text = {
				fr = "L'un des camps « pour » ou « contre » est moins bien traité que l'autre. Il doit être complété ou amélioré.",
				en = "One of the “pro” or “con” sides is treated less well than the other. It needs to be supplemented or improved.",
			},
			debate_unbalanced_category = { fr = "Débats déséquilibrés", en = "Unbalanced debates" },

			debate_redundant_label = { fr = "Débat redondant", en = "Redundant debate" },
			debate_redundant_color = "orange",
			debate_redundant_icon = "wk-icon-redundant",
			debate_redundant_title = {
				fr = "Ce débat est [[:Category:Débats redondants|redondant]]. Il fait doublon avec un autre débat du site.",
				en = "This debate is [[:Category:Redundant debates|redundant]]. It duplicates another debate on the site.",
			},
			debate_redundant_text = {
				fr =
					"Choisissez le plus complet des deux et ajoutez-y le contenu intéressant de l'autre."
					.. '<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert">'
					.. "Article détaillé : [[Aide:Fusionner des doublons]]"
					.. "</div>",
				en = "Choose the more comprehensive of the two and add interesting content from the other.",
			},
			debate_redundant_category = { fr = "Débats redondants", en = "Redundant debates" },

			debate_fanciful_label = { fr = "Débat saugrenu", en = "Fanciful debate" },
			debate_fanciful_color = "orange",
			debate_fanciful_icon = "wk-icon-saugrenu",
			debate_fanciful_title = {
				fr = "Ce débat paraît [[:Category:Débats saugrenus|saugrenu]]. Sa présence dans l'espace public n'est pas évidente.",
				en = "This debate is considered [[:Category:Fanciful debates|fanciful]]. Its presence in the public space is not obvious.",
			},
			debate_fanciful_text = {
				fr =
					"Veuillez apporter dans la [[Discussion:%s|page de discussion]] des citations montrant que des opinions contradictoires sont professées sur la question.<br>\n\n"
					.. "<b>Rappel</b> : suivant les [[Wikidébats:Principes fondateurs|principes fondateurs]], un argument n'a sa place sur Wikidébats que dans la mesure où sa présence peut être attestée dans l'espace public.",
				en =
					"Please provide quotes to the [[Talk:%s|talk page]] showing that conflicting opinions are held on the matter.<br>\n\n"
					.. "<b>Reminder</b>: according to the [[Wikidebates:Founding Principles|founding principles]], an argument has its place on Wikidebates only insofar as its presence can be attested in the public space.",
			},
			debate_fanciful_category = { fr = "Débats saugrenus", en = "Fanciful debates" },

			debate_sensitive_label = { fr = "Débat sensible", en = "Sensitive debate" },
			debate_sensitive_color = "orange",
			debate_sensitive_icon = "wk-icon-sensible",
			debate_sensitive_title = {
				fr = "Ce débat est [[:Category:Débats sensibles|sensible]]. Des arguments relayés sur cette page peuvent choquer.",
				en = "This debate is [[:Category:Sensitive debates|sensitive]]. Arguments relayed on this page may shock.",
			},
			debate_sensitive_text = {
				fr = "Ils n’ont pas été écartés dans la mesure où leur présence est attestée dans l'espace public et qu’ils ne contreviennent pas à la législation.",
				en = "<b>Reminder</b>: these arguments are only relayed insofar as their presence can be attested in the public space. They are endorsed neither by the editors of the site, nor by the [[Wikidebates:Laboratory of Methodical Debate|association]] which administers Wikidebates.",
			},
			debate_sensitive_category = { fr = "Débats sensibles", en = "Sensitive debates" },

			outline_to_improve_label = { fr = "Plan à améliorer", en = "Plan to improve" },
			outline_to_improve_color = "orange",
			outline_to_improve_icon = "wk-icon-confusion",
			outline_to_improve_title = {
				fr = "Le plan de ce débat est [[:Category:Plans de débats à améliorer|à améliorer]]. Des arguments sont redondants, sont à démêler, ou la structure peut être clarifiée.",
				en = "The plan of this debate needs to be [[:Category:Debate plans to improve|improved]]. Arguments are redundant, need to be unraveled, or its structure can be clarified.",
			},
			outline_to_improve_text = { fr = "N'hésitez pas à le revoir.", en = "Do not hesitate to review it." },
			outline_to_improve_category = { fr = "Plans de débats à améliorer", en = "Debate plans to improve" },

			debate_generated_by_ai_label = { fr = "Débat généré par IA", en = "Debate generated by AI" },
			debate_generated_by_ai_color = "orange",
			debate_generated_by_ai_icon = "wk-icon-ia",
			debate_generated_by_ai_title = {
				fr = "La base de ce débat a été [[:Category:Débats générés par IA|générée par IA]]. Rendez-le plus vivant et plus complet.",
				en = "The basis of this debate was [[:Category:Debates generated by AI|generated by AI]]. Make it livelier and more comprehensive.",
			},
			debate_generated_by_ai_text = {
				fr = "Ajoutez des arguments, des citations ou des références, réécrivez certains résumés, améliorez l'introduction, etc.",
				en = "Add arguments, quotes or references, rewrite some summaries, improve the introduction, etc.",
			},
			debate_generated_by_ai_category = { fr = "Débats générés par IA", en = "Debates generated by AI" },
		},

		subsections = {
			intro_subbanner_too_long_label = { fr = "Sous-partie trop longue", en = "Subsection too long" },
			intro_subbanner_to_develop_label = { fr = "À développer", en = "To expand" },
			intro_subbanner_unclear_label = { fr = "Peu claire", en = "Unclear" },
			intro_subbanner_biased_label = { fr = "Partisane", en = "Biased" },
			intro_subbanner_refs_missing_label = { fr = "Référence(s) manquante(s)", en = "Missing reference(s)" },
			intro_subbanner_style_to_review_label = { fr = "Style à revoir", en = "Style to review" },

			intro_subbanner_unclear_title = { fr = "Cette partie est peu claire", en = "This part is unclear" },
			intro_subbanner_unclear_text = { fr = "Cette partie est trop obscure ou jargonnante. Et si vous la rendiez plus compréhensible ?", en = "This part is too obscure or too jargony. Could you make it easier to understand?" },
			intro_subbanner_unclear_category = { fr = "Introductions de débats peu claires", en = "Unclear debate introductions" },

			intro_subbanner_biased_title = { fr = "Cette partie n'est pas neutre", en = "This part is not neutral" },
			intro_subbanner_biased_text = { fr = "Elle favorise l'un des deux camps (« pour » ou « contre ») au détriment de l'autre. L'introduction ne doit pas prendre parti, mais simplement donner les éléments utiles à la compréhension du débat.", en = "It favors one side (“pro” or “con”) at the expense of the other. The introduction should not take sides; it should only provide what is needed to understand the debate." },
			intro_subbanner_biased_category = { fr = "Introductions de débats partisanes", en = "Biased debate introductions" },

			intro_subbanner_to_develop_title = { fr = "Cette partie est à développer", en = "This part needs to be expanded" },
			intro_subbanner_to_develop_text = { fr = "Cette partie est incomplète ou trop télégraphique. Et si vous la repreniez ?", en = "This part is incomplete or too sketchy. Could you expand and improve it?" },
			intro_subbanner_to_develop_category = { fr = "Introductions de débats à développer", en = "Debate introductions to expand" },

			intro_subbanner_too_long_title = { fr = "Cette partie est trop longue", en = "This part is too long" },
			intro_subbanner_too_long_text = { fr = "Elle peut être rédigée de façon plus synthétique ou être divisée en différentes sous-parties.", en = "It could be written more concisely or split into several subsections." },
			intro_subbanner_too_long_category = { fr = "Introductions de débats trop longues", en = "Overlong debate introductions" },

			intro_subbanner_refs_missing_title = { fr = "Cette partie manque de référence(s)", en = "This part lacks reference(s)" },
			intro_subbanner_refs_missing_text = { fr = "Certains faits ou citations peu connus ne sont pas sourcés. Des références doivent être ajoutées en notes.", en = "Some facts or quotations are not sourced. References should be added as footnotes." },
			intro_subbanner_refs_missing_category = { fr = "Introductions de débats manquant de références", en = "Debate introductions lacking references" },

			intro_subbanner_style_to_review_title = { fr = "Le style de cette partie est à revoir", en = "The style of this part needs work" },
			intro_subbanner_style_to_review_text = { fr = "Cette partie est rédigée de façon personnelle (« Je pense que… ») ou trop familière. Elle doit être réécrite.", en = "This part is written in a personal tone (“I think…”) or is too casual. It should be rewritten." },
			intro_subbanner_style_to_review_category = { fr = "Introductions de débats dont le style est à revoir", en = "Debate introductions needing style work" },

			dead_link_label = { fr = "Lien mort", en = "Dead link" },
			dead_link_title = "@Debate.subsections.dead_link_label",
			dead_link_text = {
				fr = "La page n'existe plus à cette adresse. Et si vous mettiez à jour le lien ?",
				en = "The page no longer exists at this address. How about updating the link?",
			},
			dead_link_category = { fr = "Liens morts", en = "Dead links" },
	
			reference_quality_insufficient_label = {
				fr = "Référence de qualité insuffisante",
				en = "Insufficient-quality reference",
			},
			reference_quality_insufficient_title = "@Debate.subsections.reference_quality_insufficient_label",
			reference_quality_insufficient_text = {
				fr = "Des contenus plus rigoureux ou mieux argumentés peuvent être trouvés pour cet argument.",
				en = "More rigorous or better-argued sources can be found for this argument.",
			},
			reference_quality_insufficient_category = {
				fr = "Références de qualité insuffisante",
				en = "Insufficient-quality references",
			},
	
			reference_incomplete_label = { fr = "Référence incomplète", en = "Incomplete reference" },
			reference_incomplete_title = "@Debate.subsections.reference_incomplete_label",
			reference_incomplete_text = {
				fr = "Certains champs importants n'ont pas été remplis. Et si vous les complétiez ?",
				en = "Some important fields have not been filled in. How about completing them?",
			},
			reference_incomplete_category = { fr = "Références incomplètes", en = "Incomplete references" },

			displayed_title_too_long_label = { fr = "Titre affiché trop long", en = "Too long displayed title" },
			displayed_title_too_long_title = { fr = "Titre trop long", en = "Too long title" },
			displayed_title_too_long_text = { fr = "Le titre affiché de cet argument contient plusieurs phrases ou est globalement trop développé. Réécrivez le titre sous forme d'une phrase compacte qui résume de manière claire et précise l'argument.", en = "This title contains several sentences or is generally too long. Rewrite the title as a compact sentence that clearly and precisely summarizes the argument." },

			displayed_title_unclear_label = { fr = "Titre affiché peu clair", en = "Unclear displayed title" },
			displayed_title_unclear_title = { fr = "Titre peu clair", en = "Unclear title" },
			displayed_title_unclear_text = { fr = "Le titre affiché de cet argument est confus, jargonnant, trop allusif ou télégraphique.", en = "This title is confusing, jargon, too allusive or telegraphic." },

			displayed_title_disadvantageous_label = { fr = "Titre affiché désavantageux", en = "Disadvantageous displayed title" },
			displayed_title_disadvantageous_title = { fr = "Titre désavantageux", en = "Disadvantageous title" },
			displayed_title_disadvantageous_text = { fr = "Le titre affiché de cet argument présente l'argument de façon lacunaire ou caricaturée. Trouvez une formulation plus persuasive.", en = "This title presents the argument in a lacunary or caricatured way. Find a more persuasive wording." },
		},
	},

	Argument = {
		files = {
			reorganize_banner_icon	= "wk-icon-confusion",
			title_review_icon		= "wk-icon-title",
			title_review_color		= "orange",
		},
	
		forms = {
			arg_pro = "@Common.labels.arg_pro_label",
			arg_con = "@Common.labels.arg_con_label",
			justifications = "@Common.labels.justifications_label",
			objections = "@Common.labels.objections_label",
	
			edit_args_pro = "@Debate.forms.args_pro_edit_title",
			edit_args_con = "@Debate.forms.args_con_edit_title",
	
			keywords_edit_title = { fr = "Mots-clés d'argument", en = "Argument keywords" },
			banners_edit_title = { fr = "Bandeaux d'argument", en = "Argument banners" },
			summary_edit_title = { fr = "Résumé", en = "Summary" },
			quotes_edit_title = { fr = "Citations", en = "Quotes" },
			references_edit_title = { fr = "Références", en = "References" },
			sections_edit_title = { fr = "Rubriques d'argument", en = "Argument sections" },
			interlanguage_edit_title = { fr = "Interlangue d'argument", en = "Argument interlanguage" },
			detailed_debate_edit_title = { fr = "Débat dédié", en = "Dedicated debate" },
	
			search_by_keywords_title = "@Common.forms.search_by_keywords_title",
	
			justifications_form = "@Common.labels.justifications_label",
			objections_form = "@Common.labels.objections_label",
	
			new_arg_title = "@Debate.forms.new_arg_title",
		},
	
		categories = {
			arguments = "@Common.labels.arguments_label",
			keywords_missing = { fr = "Arguments sans mot-clé", en = "Arguments without keyword" },
			summary_missing = { fr = "Arguments sans résumé", en = "Arguments without summary" },
			quotes_missing = { fr = "Arguments sans citation", en = "Arguments without quote" },
			references_missing = { fr = "Arguments sans référence", en = "Arguments without references" },
			justifications_missing = { fr = "Arguments sans justification", en = "Arguments without justification" },
			objections_missing = { fr = "Arguments sans objection", en = "Arguments without objection" },
			content_missing = { fr = "Arguments sans contenu", en = "Arguments without content" },
			sections_missing = { fr = "Arguments sans rubrique", en = "Arguments without section" },
			authors_missing = { fr = "Citations sans auteur", en = "Quotes without author" },
			creation_date_missing = "@Debate.categories.creation_date_missing",
			title_very_short = { fr = "Titres d'arguments très courts", en = "Very short argument titles" },
			title_ends_with_dot = { fr = "Titres d'arguments terminant par un point", en = "Argument titles ending with a dot" },
			has_detailed_debate = { fr = "Arguments faisant l'objet d'un débat dédié", en = "Arguments with a dedicated debate" },
			has_alt_name = { fr = "Arguments portant un nom consacré", en = "Arguments with an established name" },
			uninitialized = { fr = "Arguments sans paramètre d'initialisation", en = "Arguments without an initialization parameter" },
			displayed_titles_to_review = "@Debate.categories.displayed_titles_to_review",
			breadcrumb_too_long = { fr = "Fils d'Ariane longs", en = "Long breadcrumbs" },
			content_to_transfer = { fr = "Contenu d'argument à transférer", en = "Argument content to transfer" },
		},
	
		params = {
			keywords_field = "@Common.params.keywords_field",
			search_type_field = "@Common.params.type_field",
			search_type_value = "@Common.values.type_arguments_value",
	
			page = { fr = "page", en = "page" },
			displayed_title = { fr = "titre-affiché", en = "displayed-title" },
	
			justification_type_label = "@Common.labels.justification_label",
			objection_type_label = "@Common.labels.objection_label",
		},
	
		text = {
			edit_label = "@Common.labels.edit_label",
			none_label = "@Common.labels.none_label",
	
			keywords_label_text = "@Common.text.keywords_label_text",
			keywords_edit_tt = { fr = "Modifier les mots-clés thématiques décrivant cet argument", en = "Edit the thematic keywords describing this argument" },

			warnings_title = "@Debate.text.warnings_title",
			argument_banners_edit_tt = { fr = "Modifier les bandeaux d'avertissement de l'argument", en = "Edit the argument warning banners" },
	
			summary_title = { fr = "Résumé", en = "Summary" },
			summary_edit_tt = { fr = "Modifier le résumé de l'argument", en = "Edit the argument summary" },
			none_summary_msg = { fr = "Aucun résumé n'a été entré pour le moment.", en = "No summary has been entered yet." },
	
			quotes_title = { fr = "Citations", en = "Quotes" },
			quotes_edit_tt = { fr = "Modifier les citations de l'argument", en = "Edit the argument quotes" },
			none_quotes_msg = { fr = "Aucune citation n'a été entrée pour le moment.", en = "No quotes have been entered yet." },
	
			references_title = { fr = "Références", en = "References" },
			references_edit_tt = { fr = "Modifier les références bibliographiques, sitographiques ou vidéographiques étayant l'argument", en = "Edit the references supporting this argument" },
			none_references_msg = "@Debate.text.none_references_msg",
	
			justifications_title = "@Common.labels.justifications_label",
			objections_title = "@Common.labels.objections_label",
	
			justifications_edit_tt = {
				fr = "Modifier la liste des arguments ci-dessous",
				en = "Edit the list of justifications below",
			},
			objections_edit_tt = {
				fr = "Modifier la liste des objections ci-dessous",
				en = "Edit the list of objections below",
			},
	
			justification_add_label = { fr = "Ajouter un argument", en = "Add an argument" },
			objection_add_label = { fr = "Ajouter une objection", en = "Add an objection" },
	
			justification_add_tt = {
				fr = "Ajouter une justification à l’argument : %s",
				en = "Add a justification to: %s",
			},
			objection_add_tt = {
				fr = "Ajouter une objection à l’argument : %s",
				en = "Add an objection to: %s",
			},
	
			none_justifications_msg = { fr = "Aucun argument n'a été entré pour le moment.", en = "No arguments have been entered yet." },
			none_objections_msg = { fr = "Aucune objection n'a été entrée pour le moment.", en = "No objections have been entered yet." },
			none_content_msg = { fr = "Aucun contenu n'a été entré.", en = "No content has been entered." },
	
			none_justifications_short_msg = { fr = "Aucun argument n'a été entré.", en = "No arguments have been entered yet." },
			none_objections_short_msg = { fr = "Aucune objection n'a été entrée.", en = "No objections have been entered yet." },
	
			reorganize_link_label = { fr = "réorganiser", en = "reorganized" },
	
			detailed_debate_title = { fr = "Débat dédié", en = "Dedicated debate" },
			detailed_debate_edit_tt = { fr = "Modifier le débat sous-jacent à l'argument : %s", en = "Edit the underlying debate for: %s" },
			detailed_debate_label_text = { fr = "Débat dédié : ", en = "Dedicated debate: " },
			arg_is_debate_msg = {
				fr = [[L'argument <span style="font-weight: normal;">« %s »</span> fait l'objet d'un débat à part entière.]],
				en = [[The argument <span style="font-weight: normal;">“%s”</span> has its own dedicated debate.]]
			},
			title_too_short_msg = { fr = "Ce titre de page donne-t-il une idée claire et précise de l'argument ?", en = "Does this page title clearly describe the argument?" },
			title_ends_with_dot_msg = { fr = "Attention : ce titre de page se termine par un point.", en = "Warning: this page title ends with a dot." },
			rename_page_label = "@Common.labels.rename_page_label",
			if_needed_msg = { fr = "si ce n'est pas le cas.", en = "if not." },
	
			breadcrumb_root_label = "@Common.labels.arguments_label",
			rename_link_label = "@Common.text.rename_link_label",
	
			sections_edit_tt = { fr = "Modifier les rubriques de l'argument", en = "Edit the argument sections" },
			interlanguage_edit_tt = { fr = "Modifier les liens interlangues de l'argument", en = "Edit the interlanguage links" },
	
			seo_description = {
				fr = "Arguments pour et contre : critiques et justifications, défenseurs et opposants.",
				en = "Arguments for and against: critiques and justifications, defenders and opponents.",
			},
	
			arg_new_title_field_base = "@Debate.forms.new_arg_title",
	
			parent_used_in_debate_1 = { fr = "Cet argument est utilisé dans le débat %s", en = "This argument is used in the debate %s" },
			parent_used_in_debate_n = { fr = "Cet argument est utilisé dans les débats %s", en = "This argument is used in the debates %s" },
	
			parent_is_pro_in_1 = { fr = "Cet argument est un argument « pour » dans le débat %s", en = "This argument is a “pro” argument in the debate %s" },
			parent_is_pro_in_n = { fr = "Cet argument est un argument « pour » dans les débats %s", en = "This argument is a “pro” argument in the debates %s" },
	
			parent_is_con_in_1 = { fr = "Cet argument est un argument « contre » dans le débat %s", en = "This argument is a “con” argument in the debate %s" },
			parent_is_con_in_n = { fr = "Cet argument est un argument « contre » dans les débats %s", en = "This argument is a “con” argument in the debates %s" },
	
			parent_is_justification_of = { fr = "Cet argument est une justification de %s", en = "This argument is a justification of %s" },
			parent_is_objection_to = { fr = "Cet argument est une objection à %s", en = "This argument is an objection to %s" },
	
			search_by_keyword_tt = "@Debate.text.search_by_keyword_tt",
	
			parent_debates_title_1 = { fr = "Débat parent", en = "Parent debate" },
			parent_debates_title_n = { fr = "Débats parents", en = "Parent debates" },
	
			list_conjunction_text = "@Common.text.list_conjunction_text",
	
			init_warning_title = {
				fr = "Cet argument faisait initialement partie de la rubrique « %s » dans la page",
				en = "This argument was originally part of the “%s” section on the page",
			},
		
			init_warning_intro = {
				fr = "Si cet argument a encore sa place à cet endroit, vous pouvez le réinsérer en cliquant sur le bouton ci-dessous :",
				en = "If this argument still belongs there, you can reinsert it by clicking the button below:",
			},
		
			init_yes_finish_label = {
				fr = "Réinsérer l'argument",
				en = "Reinsert the argument",
			},
		
			init_yes_tt = {
				fr = "Réinsérer l'argument dans la page : %s",
				en = "Reinsert the argument into the page: %s",
			},
		
			init_ok_text = {
				fr = "Argument réinséré dans la page [[%s]]",
				en = "Argument reinserted into the page [[%s]]",
			},
		
			init_summary_pro = {
				fr = "/* Arguments « pour » */ Réinsertion de l’argument orphelin : ",
				en = "/* Pros */ Reinserted orphaned argument: ",
			},
		
			init_summary_con = {
				fr = "/* Arguments « contre » */ Réinsertion de l’argument orphelin : ",
				en = "/* Cons */ Reinserted orphaned argument: ",
			},
		
			init_summary_justif = {
				fr = "/* Justifications */ Réinsertion de l’argument orphelin : ",
				en = "/* Justifications */ Reinserted orphaned argument: ",
			},
		
			init_summary_obj = {
				fr = "/* Objections */ Réinsertion de l’argument orphelin : ",
				en = "/* Objections */ Reinserted orphaned argument: ",
			},
	
			unknown_author = "@Debate.text.unknown_author",
			volume_label = { fr = "vol.", en = "Volume", },
			issue_label = { fr = "n°", en = "Issue", },
		},

		urls = {
			home = "@Debate.urls.home",
			home_label = "@Debate.urls.home_label",
			wiki_base = "@Debate.urls.wiki_base",
			discussion_prefix = "@Debate.urls.discussion_prefix",
			category_prefix = "@Debate.urls.category_prefix",
			portal_prefix = "@Debate.urls.portal_prefix",
			property_prefix = "@Debate.urls.property_prefix",
			sections_label = "@Debate.urls.sections_label",
			arguments_label = { fr = "Arguments", en = "Arguments" },
			justification_label = { fr = "Justification", en = "Justification" },
			objection_label = { fr = "Objection", en = "Objection" },
		},
	
		props = {
			arg_number = { fr = "Numéro d'argument", en = "Argument number" },
			debate_number = { fr = "Numéro de débat", en = "Debate number" },
			justif_list = { fr = "Liste des justifications", en = "Justification list" },
			obj_list = { fr = "Liste des objections", en = "Objection list" },
			detailed_debate = { fr = "Débat dédié", en = "Dedicated debate" },
			argument_map = "@Common.labels.argument_map_label",
			keyword = "@Common.props.keyword",
			arg_page_name = { fr = "Nom de page d'argument", en = "Argument page name" },
			section = { fr = "Rubrique", en = "Section" },
	
			arg_pro = "@Common.labels.arg_pro_label",
			arg_con = "@Common.labels.arg_con_label",
			justif = "@Common.labels.justification_label",
			obj = "@Common.labels.objection_label",
	
			breadcrumb = { fr = "Fil d'Ariane", en = "Breadcrumb" },
			debate_name = { fr = "Nom de débat", en = "Debate name" },
	
			parent_debate = "@Debate.props.parent_debate",
			parent_argument = { fr = "Argument parent", en = "Parent argument" },
	
			article_name = "@Common.props.article_name",
			work_name = "@Common.props.work_name",
			publishing_house = "@Common.props.publishing_house",
			place_of_publication = "@Common.props.place_of_publication",
			author = "@Common.props.author",
			website_name = "@Common.props.website_name",
		},

		seo = {
			title_separator		= "@Debate.seo.title_separator",
			image				= "@Debate.seo.image",
			image_alt			= "@Debate.seo.image_alt",
			site_name			= "@Debate.seo.site_name",
			twitter_site		= "@Debate.seo.twitter_site",
			locale				= "@Debate.seo.locale",
			author				= "@Debate.seo.author",
			section 			= "@Common.labels.arguments_label",
			space				= { fr = " ", en = "" },
			shortdesc_plain		= { fr = "arguments et objections", en = "arguments and objections" },

			--	Spécifique Argument
			description = {
				fr = "Arguments pour et contre : %s. Critiques et justifications, défenseurs et opposants.",
				en = "Arguments for and against %s: defence and criticism, justifications and objections, proofs and refutations, supporters and opponents.",
			},
			breadcrumb_home_label = "@Debate.seo.breadcrumb_home_label",
			breadcrumb_arguments_label = "@Common.labels.arguments_label",
		},

		banners = {
			argument_orphan_label = { fr = "Argument orphelin", en = "Orphan argument" },
			argument_orphan_color = "orange",
			argument_orphan_icon = "wk-icon-orphan",
			argument_orphan_title = {
				fr = "Cet argument est [[:Catégorie:Arguments orphelins|orphelin]]. Il n'a aucun argument ou débat parent.",
				en = "This argument is an [[:Category:Orphan arguments|orphan]]. It has no parent argument or debate.",
			},
			argument_orphan_text = {
				fr = "Ne voyez-vous pas d'argument auquel il pourrait servir de justification ou d'objection ?",
				en = "Can you think of an argument for which it could serve as a justification or an objection?",
			},
			argument_orphan_category = { fr = "Arguments orphelins", en = "Orphan arguments" },

			title_incomplete_label = { fr = "Titre incomplet", en = "Incomplete title" },
			title_incomplete_color = "@Argument.files.title_review_color",
			title_incomplete_icon = "@Argument.files.title_review_icon",
			title_incomplete_title = {
				fr = "Le titre de cet argument est [[:Catégorie:Titres d'arguments incomplets|incomplet]]. Il est ambigu ou incompréhensible à lui seul.",
				en = "The title of this argument is [[:Category:Incomplete argument titles|incomplete]]. It is ambiguous or not understandable on its own.",
			},
			title_incomplete_text = {
				fr =
					"[[Spécial:Renommage/%s|Réécrivez le titre]] sous forme d'une phrase complète qui donne une idée claire et précise de l'argument."
					.. '<div class="bandeau-section wk-icon-before wk-icon-loupe mw-no-invert">'
					.. "Article détaillé : [[Aide:Corriger un titre d'argument incomplet]]"
					.. "</div>",
				en = "[[Special:MovePage/%s|Rewrite the title]] as a complete sentence that clearly and precisely conveys the argument.",
			},
			title_incomplete_category = { fr = "Titres d'arguments incomplets", en = "Incomplete argument titles" },

			title_disadvantageous_label = { fr = "Titre désavantageux", en = "Disadvantageous title" },
			title_disadvantageous_color = "@Argument.files.title_review_color",
			title_disadvantageous_icon = "@Argument.files.title_review_icon",
			title_disadvantageous_title = {
				fr = "Le titre de cet argument est [[:Catégorie:Titres d'arguments désavantageux|désavantageux]]. Il présente l'argument de façon lacunaire ou caricaturée.",
				en = "The title of this argument is [[:Category:Disadvantageous argument titles|disadvantageous]]. It presents the argument in a partial or caricatured way.",
			},
			title_disadvantageous_text = {
				fr = "[[Spécial:Renommage/%s|Trouvez une formulation]] plus convaincante.",
				en = "[[Special:MovePage/%s|Find a more convincing phrasing]].",
			},
			title_disadvantageous_category = { fr = "Titres d'arguments désavantageux", en = "Disadvantageous argument titles" },

			title_unclear_label = { fr = "Titre peu clair", en = "Unclear title" },
			title_unclear_color = "@Argument.files.title_review_color",
			title_unclear_icon = "@Argument.files.title_review_icon",
			title_unclear_title = {
				fr = "Le titre de cet argument est [[:Catégorie:Titres d'arguments peu clairs|peu clair]]. Il est confus, jargonnant, trop allusif ou télégraphique.",
				en = "The title of this argument is [[:Category:Unclear argument titles|unclear]]. It is confusing, jargon-heavy, too allusive, or too telegraphic.",
			},
			title_unclear_text = {
				fr = "[[Spécial:Renommage/%s|Trouvez une formulation]] plus claire.",
				en = "[[Special:MovePage/%s|Find a clearer phrasing]].",
			},
			title_unclear_category = { fr = "Titres d'arguments peu clairs", en = "Unclear argument titles" },

			title_too_long_label = { fr = "Titre trop long", en = "Title too long" },
			title_too_long_color = "@Argument.files.title_review_color",
			title_too_long_icon = "@Argument.files.title_review_icon",
			title_too_long_title = {
				fr = "Le titre de cet argument est [[:Catégorie:Titres d'arguments trop longs|trop long]]. Il contient plusieurs phrases ou est globalement trop développé.",
				en = "The title of this argument is [[:Category:Overly long argument titles|too long]]. It contains multiple sentences or is overall too verbose.",
			},
			title_too_long_text = {
				fr = "[[Spécial:Renommage/%s|Réécrivez le titre]] sous forme d'une phrase compacte qui résume de manière claire et précise l'argument.",
				en = "[[Special:MovePage/%s|Rewrite the title]] as a compact sentence that clearly and precisely summarizes the argument.",
			},
			title_too_long_category = { fr = "Titres d'arguments trop longs", en = "Overly long argument titles" },

			--	ARGUMENT WARNINGS (inchangé)
			argument_sensitive_label = { fr = "Argument sensible", en = "Sensitive argument" },
			argument_sensitive_color = "orange",
			argument_sensitive_icon = "@Debate.banners.debate_sensitive_icon",
			argument_sensitive_title = {
				fr = "Cet argument est considéré [[:Catégorie:Arguments sensibles|sensible]]. Son contenu peut choquer.",
				en = "This argument is considered [[:Category:Sensitive arguments|sensitive]]. Its content may be shocking.",
			},
			argument_sensitive_text = {
				fr = "Il n’a pas été écarté dans la mesure où sa présence est attestée dans l'espace public et qu’il ne contrevient pas à la législation.",
				en = "It has not been removed insofar as its presence can be attested in the public space and it does not violate the law.",
			},
			argument_sensitive_category = { fr = "Arguments sensibles", en = "Sensitive arguments" },

			argument_fanciful_label = { fr = "Argument saugrenu", en = "Fanciful argument" },
			argument_fanciful_color = "orange",
			argument_fanciful_icon = "wk-icon-saugrenu",
			argument_fanciful_title = {
				fr = "Cet argument est considéré [[:Catégorie:Arguments saugrenus|saugrenu]]. Une citation est nécessaire afin d'apporter la preuve de son existence dans l'espace public.",
				en = "This argument is considered [[:Category:Fanciful arguments|fanciful]]. A citation is needed to prove that it exists in the public space.",
			},
			argument_fanciful_text = {
				fr = "<b>Rappel</b> : suivant les [[Wikidébats:Principes fondateurs|principes fondateurs]], les arguments ne sont relayés sur Wikidébats que dans la mesure où leur présence peut être attestée dans l'espace public.",
				en = "<b>Reminder</b>: according to the [[Wikidebates:Founding Principles|founding principles]], arguments are only relayed insofar as their presence can be attested in the public space.",
			},
			argument_fanciful_category = { fr = "Arguments saugrenus", en = "Fanciful arguments" },

			argument_potentially_illegal_label = { fr = "Argument potentiellement illégal", en = "Potentially illegal argument" },
			argument_potentially_illegal_color = "rouge",
			argument_potentially_illegal_icon = "wk-icon-illegal",
			argument_potentially_illegal_title = {
				fr = "Cet argument est [[:Catégorie:Arguments potentiellement illégaux|potentiellement illégal]].",
				en = "This argument is [[:Category:Potentially illegal arguments|potentially illegal]].",
			},
			argument_potentially_illegal_text = {
				fr = "<b>Rappel</b> : suivant les [[Wikidébats:Principes fondateurs|principes fondateurs]], tous les arguments ont leur place sur Wikidébats à condition qu'ils ne contreviennent pas à la loi.",
				en = "<b>Reminder</b>: according to the [[Wikidebates:Founding Principles|founding principles]], all arguments may be included as long as they do not break the law.",
			},
			argument_potentially_illegal_category = { fr = "Arguments potentiellement illégaux", en = "Potentially illegal arguments" },

			argument_generated_by_ai_label = { fr = "Argument généré par IA", en = "Argument generated by AI" },
			argument_generated_by_ai_color = "orange",
			argument_generated_by_ai_icon = "wk-icon-ia",
			argument_generated_by_ai_title = {
				fr = "Cet argument a été [[:Category:Arguments générés par IA|généré par IA]]. Pensez-vous qu'un être humain ferait mieux ?",
				en = "This argument was [[:Category:Arguments generated by AI|generated by AI]]. Do you think a human would do better?",
			},
			argument_generated_by_ai_text = {
				fr = "Vous pouvez le prouver en améliorant le contenu de l'argument.",
				en = "You can prove it by improving the content of this argument.",
			},
			argument_generated_by_ai_category = { fr = "Arguments générés par IA", en = "Arguments generated by AI" },
		},

		subsection_banners = {
			summary_disadvantageous_label = { fr = "Le résumé de cet argument est désavantageux", en = "This argument's summary is disadvantageous" },
			summary_disadvantageous_title = {
				fr = "Le résumé de cet argument est désavantageux",
				en = "This argument's summary is disadvantageous",
			},
			summary_disadvantageous_text = {
				fr = "L'argument est présenté de façon lacunaire ou caricaturée. Une version plus convaincante doit être rédigée.",
				en = "The argument is presented in a partial or caricatured way. A more convincing version should be written.",
			},
			summary_disadvantageous_category = { fr = "Résumés d'arguments désavantageux", en = "Disadvantageous argument summaries" },
	
			summary_to_be_written_label = { fr = "Le résumé de l'argument est encore à rédiger", en = "This argument's summary still needs to be written" },
			summary_to_be_written_image = "@Debate.banners.debate_sensitive_icon",
			summary_to_be_written_title = {
				fr = "Le résumé de l'argument est encore à rédiger",
				en = "This argument's summary still needs to be written",
			},
			summary_to_be_written_text = {
				fr = "Aucun contenu n'a été apporté ou le texte entré correspond à une prise de notes. Et si vous le rédigiez ?",
				en = "No content has been added, or the text is just notes. How about writing it properly?",
			},
			summary_to_be_written_category = { fr = "Résumés d'arguments à rédiger", en = "Argument summaries to be written" },
	
			summary_unclear_label = { fr = "Le résumé de cet argument n'est pas assez clair", en = "This argument's summary is not clear enough" },
			summary_unclear_title = {
				fr = "Le résumé de cet argument n'est pas assez clair",
				en = "This argument's summary is not clear enough",
			},
			summary_unclear_text = {
				fr = "Ce résumé est confus, jargonnant, trop complexe ou trop télégraphique. Il doit être rendu plus compréhensible.",
				en = "This summary is confusing, jargon-heavy, too complex, or too telegraphic. It should be made easier to understand.",
			},
			summary_unclear_category = { fr = "Résumés d'arguments peu clairs", en = "Unclear argument summaries" },
	
			summary_too_long_label = { fr = "Le résumé de cet argument est trop long", en = "This argument's summary is too long" },
			summary_too_long_title = {
				fr = "Le résumé de cet argument est trop long",
				en = "This argument's summary is too long",
			},
			summary_too_long_text = {
				fr = "Il comporte des redondances, des phrases lourdes, ou est globalement trop développé. Le texte doit être allégé.",
				en = "It contains redundancies, heavy phrasing, or is overall too verbose. The text should be shortened.",
			},
			summary_too_long_category = { fr = "Résumés d'arguments trop longs", en = "Overlong argument summaries" },
	
			summary_to_separate_label = { fr = "Ce résumé mélange plusieurs arguments", en = "This summary mixes several arguments" },
			summary_to_separate_title = {
				fr = "Ce résumé mélange plusieurs arguments",
				en = "This summary mixes several arguments",
			},
			summary_to_separate_text = {
				fr = "Ce résumé mêle des arguments qu'il s'agit de dissocier. Créez une page spécifique pour chacun d'eux.",
				en = "This summary mixes arguments that should be separated. Create a dedicated page for each of them.",
			},
			summary_to_separate_category = { fr = "Résumés d'arguments à séparer", en = "Argument summaries to separate" },
	
			summary_style_to_review_label = { fr = "Le style de ce résumé est à revoir", en = "This summary's style should be reviewed" },
			summary_style_to_review_title = {
				fr = "Le style de ce résumé est à revoir",
				en = "This summary's style should be reviewed",
			},
			summary_style_to_review_text = {
				fr = "Ce résumé est rédigé de façon personnelle (« Je pense que… ») ou trop familière. Il doit être réécrit.",
				en = "This summary is written in a personal (“I think…”) or overly casual way. It should be rewritten.",
			},
			summary_style_to_review_category = { fr = "Résumés d'arguments dont le style est à revoir", en = "Argument summaries with style to review" },
	
			summary_to_separate_alias = { fr = "Arguments à séparer", en = "Arguments to separate banner" },
			summary_disadvantageous_alias = { fr = "Résumé désavantageux", en = "Disadvantageous summary banner" },
			summary_style_to_review_alias = { fr = "Style à revoir", en = "Style to review banner" },
			summary_to_be_written_alias = { fr = "Résumé à rédiger", en = "Summary to be written banner" },
			summary_too_long_alias = { fr = "Résumé trop long", en = "Too long summary banner" },
			summary_unclear_alias = { fr = "Résumé peu clair", en = "Unclear summary banner" },
	
			justifications_to_reorganize_alias		= { fr = "Justifications à réorganiser", en = "Justifications to reorganize" },
			objections_to_reorganize_alias			= { fr = "Objections à réorganiser", en = "Objections to reorganize" },
	
			justifications_to_reorganize_label		= {
				fr = "Justifications à réorganiser",
				en = "Justifications to reorganize",
			},
			justifications_to_reorganize_title		= {
				fr = "Cette sous-partie est à",
				en = "This subsection needs to be",
			},
			justifications_to_reorganize_text		= {
				fr = "Des arguments sont redondants, sont à démêler, ou la structure peut être clarifiée.",
				en = "Some arguments are redundant, intertwined, or the structure needs clarification.",
			},
			justifications_to_reorganize_category	= {
				fr = "Justifications à réorganiser",
				en = "Justifications to reorganize",
			},
	
			objections_to_reorganize_label			= {
				fr = "Objections à réorganiser",
				en = "Objections to reorganize",
			},
			objections_to_reorganize_title			= {
				fr = "Cette sous-partie est à",
				en = "This subsection needs to be",
			},
			objections_to_reorganize_text			= {
				fr = "Des objections sont redondantes, sont à démêler, ou la structure peut être clarifiée.",
				en = "Some objections are redundant, intertwined, or the structure needs clarification.",
			},
			objections_to_reorganize_category		= {
				fr = "Objections à réorganiser",
				en = "Objections to reorganize",
			},
			quote_too_short_label		= { fr = "Citation trop courte", en = "Too short quote" },
			quote_too_short_title		= { fr = "Cette citation est trop courte", en = "This quote is too short" },
			quote_too_short_text		= {
				fr = "L'extrait retenu ne contient pas de raisonnement ni d'exemple. Sélectionnez un extrait plus long ou supprimez la citation.",
				en = "The selected excerpt contains no reasoning or example. Choose a longer excerpt or remove the quote.",
			},
			quote_too_short_category	= { fr = "Citations trop courtes", en = "Too short quotes" },
	
			quote_too_long_label		= { fr = "Citation trop longue", en = "Too long quote" },
			quote_too_long_title		= { fr = "Cette citation est trop longue", en = "This quote is too long" },
			quote_too_long_text			= {
				fr = "L'extrait retenu est trop long ou mêle des arguments distincts. Supprimez les passages superflus, si besoin en utilisant les crochets [...], ou découpez cet extrait en plusieurs citations centrées chacune sur un argument.",
				en = "The selected excerpt is too long or mixes distinct arguments. Remove unnecessary passages (use brackets [...] if needed), or split it into several quotes, each focused on one argument.",
			},
			quote_too_long_category		= { fr = "Citations trop longues", en = "Too long quotes" },
	
			quote_ref_incomplete_label		= { fr = "Référence de la citation incomplète", en = "Incomplete quote reference" },
			quote_ref_incomplete_title		= { fr = "La référence de la citation est incomplète", en = "The quote reference is incomplete" },
			quote_ref_incomplete_text		= {
				fr = "Certains champs importants (auteur, titre, date, ...) n'ont pas été remplis. Et si vous les complétiez ?",
				en = "Some important fields (author, title, date, ...) have not been filled in. How about completing them?",
			},
			quote_ref_incomplete_category	= { fr = "Références de citations incomplètes", en = "Incomplete quote references" },

			quote_translated_label		= { fr = "Citation traduite par IA", en = "AI-translated quote" },
			quote_translated_title		= { fr = "Cette citation a été traduite par IA", en = "This quote was translated by AI" },
			quote_translated_text		= {
				fr = "Si vous constatez des erreurs dans la traduction, merci de les corriger. Si une traduction officielle existe, il est préférable de l'utiliser.",
				en = "If you notice any errors in the translation, please correct them. If an official translation exists, it is preferable to use it.",
			},
			quote_translated_category	= { fr = "Citations traduites par IA", en = "AI-translated quotes" },
	
			dead_link_label = "@Debate.subsections.dead_link_label",
			dead_link_title = "@Debate.subsections.dead_link_label",
			dead_link_text = "@Debate.subsections.dead_link_text",
			dead_link_category = "@Debate.subsections.dead_link_category",
			
			reference_quality_insufficient_label = "@Debate.subsections.reference_quality_insufficient_label",
			reference_quality_insufficient_title = "@Debate.subsections.reference_quality_insufficient_label",
			reference_quality_insufficient_text = "@Debate.subsections.reference_quality_insufficient_text",
			reference_quality_insufficient_category = "@Debate.subsections.reference_quality_insufficient_category",
			
			reference_incomplete_label = "@Debate.subsections.reference_incomplete_label",
			reference_incomplete_title = "@Debate.subsections.reference_incomplete_label",
			reference_incomplete_text = "@Debate.subsections.reference_incomplete_text",
			reference_incomplete_category = "@Debate.subsections.reference_incomplete_category",
			
			references_too_many_label = {
				fr = "Il y a trop de références",
				en = "There are too many references",
			},
			references_too_many_title = "@Argument.subsection_banners.references_too_many_label",
			references_too_many_text = {
				fr = "Cette rubrique ne devrait pas dépasser une dizaine de références. Sélectionnez avant tout les références incontournables.",
				en = "This section should not exceed about ten references. Prioritize the most essential sources.",
			},
			references_too_many_category = {
				fr = "Trop de références",
				en = "Too many references",
			},
			references_many_incomplete_label = {
				fr = "De nombreuses références sont incomplètes",
				en = "Many references are incomplete",
			},
			references_many_incomplete_title = "@Argument.subsection_banners.references_many_incomplete_label",
			references_many_incomplete_text = {
				fr = "Il manque des informations importantes à trop de références. Celles-ci doivent être complétées.",
				en = "Too many references lack important information. They need to be completed.",
			},
			references_many_incomplete_category = {
				fr = "Nombreuses références incomplètes",
				en = "Many incomplete references",
			},

			displayed_title_too_long_label = "@Debate.subsections.displayed_title_too_long_label",
			displayed_title_too_long_title = "@Debate.subsections.displayed_title_too_long_title",
			displayed_title_too_long_text = "@Debate.subsections.displayed_title_too_long_text",

			displayed_title_unclear_label = "@Debate.subsections.displayed_title_unclear_label",
			displayed_title_unclear_title = "@Debate.subsections.displayed_title_unclear_title",
			displayed_title_unclear_text = "@Debate.subsections.displayed_title_unclear_text",

			displayed_title_disadvantageous_label = "@Debate.subsections.displayed_title_disadvantageous_label",
			displayed_title_disadvantageous_title = "@Debate.subsections.displayed_title_disadvantageous_title",
			displayed_title_disadvantageous_text = "@Debate.subsections.displayed_title_disadvantageous_text",
		},
	},

	ArgumentMap = {
		props = {
			arg_map = "@Common.labels.argument_map_label",
			pro_list = "@Debate.props.pro_list",
			con_list = "@Debate.props.con_list",
			justifs_list = "@Argument.props.justif_list",
			objs_list = "@Argument.props.obj_list",
		},
		text = {
			arg_map_pro_label = "@Debate.text.arg_map_pro_label",
			arg_map_con_label = "@Debate.text.arg_map_con_label",
			justifs_label = "@Common.labels.justifications_label",
			objs_label = "@Common.labels.objections_label",
			none_pro_args_msg_short = "@Debate.text.none_pro_args_msg_short",
			none_con_args_msg_short = "@Debate.text.none_con_args_msg_short",
			none_justifications_msg = "@Argument.text.none_justifications_msg",
			none_objections_msg = "@Argument.text.none_objections_msg",
		},
	},
}