/**
 * Emotio Team — Gutenberg block (live server-rendered preview).
 * Built without a build step: plain wp.element calls.
 */
/* global wp */
(function (wp) {
	'use strict';

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var ServerSideRender = wp.serverSideRender;
	var PanelBody = wp.components.PanelBody;
	var SelectControl = wp.components.SelectControl;
	var RangeControl = wp.components.RangeControl;
	var ToggleControl = wp.components.ToggleControl;
	var TextControl = wp.components.TextControl;

	registerBlockType('emotio-team/team', {
		title: __('Team Members', 'emotio-team'),
		description: __('Grid or slider of team members with search and filtering.', 'emotio-team'),
		icon: 'groups',
		category: 'widgets',
		keywords: [__('team', 'emotio-team'), __('staff', 'emotio-team'), __('people', 'emotio-team')],
		supports: { html: false, align: ['wide', 'full'] },

		edit: function (props) {
			var a = props.attributes;
			var set = props.setAttributes;

			return el(
				wp.element.Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Layout', 'emotio-team'), initialOpen: true },
						el(SelectControl, {
							label: __('Layout', 'emotio-team'),
							value: a.layout,
							options: [
								{ label: __('Grid', 'emotio-team'), value: 'grid' },
								{ label: __('Slider', 'emotio-team'), value: 'slider' },
								{ label: __('List', 'emotio-team'), value: 'list' }
							],
							onChange: function (v) { set({ layout: v }); }
						}),
						a.layout === 'slider' && el(SelectControl, {
							label: __('Slider style', 'emotio-team'),
							value: a.sliderStyle,
							options: [
								{ label: __('Drag / swipe with momentum', 'emotio-team'), value: 'drag' },
								{ label: __('Paged with arrows & dots', 'emotio-team'), value: 'paged' }
							],
							onChange: function (v) { set({ sliderStyle: v }); }
						}),
						el(SelectControl, {
							label: __('Element spacing', 'emotio-team'),
							value: a.spacing,
							options: [
								{ label: __('Tight', 'emotio-team'), value: 'tight' },
								{ label: __('Normal', 'emotio-team'), value: 'normal' },
								{ label: __('Spaced', 'emotio-team'), value: 'spaced' }
							],
							onChange: function (v) { set({ spacing: v }); }
						}),
						el(RangeControl, {
							label: __('Columns', 'emotio-team'),
							min: 1,
							max: 6,
							value: a.columns,
							onChange: function (v) { set({ columns: v }); }
						}),
						el(SelectControl, {
							label: __('Card style', 'emotio-team'),
							value: a.style,
							options: [
								{ label: __('Cards', 'emotio-team'), value: 'cards' },
								{ label: __('Minimal', 'emotio-team'), value: 'minimal' },
								{ label: __('Image overlay', 'emotio-team'), value: 'overlay' },
								{ label: __('Circle portrait', 'emotio-team'), value: 'circle' }
							],
							onChange: function (v) { set({ style: v }); }
						}),
						el(SelectControl, {
							label: __('Hover effect', 'emotio-team'),
							value: a.hover,
							options: [
								{ label: __('Lift', 'emotio-team'), value: 'lift' },
								{ label: __('Image zoom', 'emotio-team'), value: 'zoom' },
								{ label: __('Swap to hover photo', 'emotio-team'), value: 'swap' },
								{ label: __('Grayscale to colour', 'emotio-team'), value: 'grayscale' },
								{ label: __('None', 'emotio-team'), value: 'none' }
							],
							onChange: function (v) { set({ hover: v }); }
						}),
						el(SelectControl, {
							label: __('Photo ratio', 'emotio-team'),
							value: a.imageRatio,
							options: [
								{ label: '3:4', value: '3-4' },
								{ label: '1:1', value: '1-1' },
								{ label: '2:3', value: '2-3' },
								{ label: '4:3', value: '4-3' },
								{ label: '16:9', value: '16-9' }
							],
							onChange: function (v) { set({ imageRatio: v }); }
						})
					),
					el(
						PanelBody,
						{ title: __('Who to show', 'emotio-team'), initialOpen: false },
						el(TextControl, {
							label: __('Department slugs', 'emotio-team'),
							help: __('Comma-separate for multiple; empty shows everyone.', 'emotio-team'),
							value: a.department,
							onChange: function (v) { set({ department: v }); }
						}),
						el(TextControl, {
							label: __('Skill / tag slugs', 'emotio-team'),
							value: a.tag,
							onChange: function (v) { set({ tag: v }); }
						}),
						el(RangeControl, {
							label: __('Limit (-1 = all)', 'emotio-team'),
							min: -1,
							max: 50,
							value: a.limit,
							onChange: function (v) { set({ limit: v }); }
						}),
						el(SelectControl, {
							label: __('Order by', 'emotio-team'),
							value: a.orderby,
							options: [
								{ label: __('Custom order', 'emotio-team'), value: 'menu_order' },
								{ label: __('Name', 'emotio-team'), value: 'title' },
								{ label: __('Newest first', 'emotio-team'), value: 'date' },
								{ label: __('Random', 'emotio-team'), value: 'rand' }
							],
							onChange: function (v) { set({ orderby: v }); }
						})
					),
					el(
						PanelBody,
						{ title: __('Features', 'emotio-team'), initialOpen: false },
						el(ToggleControl, {
							label: __('Department filter chips', 'emotio-team'),
							checked: a.showFilter,
							onChange: function (v) { set({ showFilter: v }); }
						}),
						el(ToggleControl, {
							label: __('Live search box', 'emotio-team'),
							checked: a.showSearch,
							onChange: function (v) { set({ showSearch: v }); }
						}),
						el(ToggleControl, {
							label: __('Social icons', 'emotio-team'),
							checked: a.showSocial,
							onChange: function (v) { set({ showSocial: v }); }
						}),
						el(ToggleControl, {
							label: __('Short bio on cards', 'emotio-team'),
							checked: a.showBio,
							onChange: function (v) { set({ showBio: v }); }
						}),
						el(SelectControl, {
							label: __('Card click', 'emotio-team'),
							value: a.link,
							options: [
								{ label: __('Open profile modal', 'emotio-team'), value: 'modal' },
								{ label: __('Slide-out profile panel', 'emotio-team'), value: 'panel' },
								{ label: __('Go to profile page', 'emotio-team'), value: 'page' },
								{ label: __('Not clickable', 'emotio-team'), value: 'none' }
							],
							onChange: function (v) { set({ link: v }); }
						}),
						el(TextControl, {
							label: __('Accent colour override (hex)', 'emotio-team'),
							placeholder: '#1f2937',
							value: a.accent,
							onChange: function (v) { set({ accent: v }); }
						})
					),
					el(
						PanelBody,
						{ title: __('Typography & colours', 'emotio-team'), initialOpen: false },
						el(TextControl, { label: __('Name size (px)', 'emotio-team'), placeholder: __('inherit', 'emotio-team'), value: a.nameSize, onChange: function (v) { set({ nameSize: v }); } }),
						el(TextControl, { label: __('Name colour (hex)', 'emotio-team'), placeholder: __('inherit', 'emotio-team'), value: a.nameColor, onChange: function (v) { set({ nameColor: v }); } }),
						el(TextControl, { label: __('Job title size (px)', 'emotio-team'), placeholder: __('inherit', 'emotio-team'), value: a.titleSize, onChange: function (v) { set({ titleSize: v }); } }),
						el(TextControl, { label: __('Job title colour (hex)', 'emotio-team'), placeholder: __('accent', 'emotio-team'), value: a.titleColor, onChange: function (v) { set({ titleColor: v }); } }),
						el(TextControl, { label: __('Snippet size (px)', 'emotio-team'), placeholder: __('inherit', 'emotio-team'), value: a.bioSize, onChange: function (v) { set({ bioSize: v }); } }),
						el(TextControl, { label: __('Snippet colour (hex)', 'emotio-team'), placeholder: __('inherit', 'emotio-team'), value: a.bioColor, onChange: function (v) { set({ bioColor: v }); } }),
						el(TextControl, { label: __('Social icon size (px)', 'emotio-team'), placeholder: '18', value: a.socialSize, onChange: function (v) { set({ socialSize: v }); } }),
						el(TextControl, { label: __('Social icon colour (hex)', 'emotio-team'), placeholder: __('inherit', 'emotio-team'), value: a.socialColor, onChange: function (v) { set({ socialColor: v }); } })
					)
				),
				el(ServerSideRender, {
					block: 'emotio-team/team',
					attributes: props.attributes
				})
			);
		},

		save: function () {
			return null;
		}
	});
})(window.wp);
