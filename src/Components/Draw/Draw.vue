<template>
	<div class="container">
		<ul>
			<li
				v-if="textEditor"
				:class="{active: isActive('text')}"
				@click.prevent="setActive('text')">
				<img :src="$options.icons.textIcon" alt="Text">
				Text
			</li>
			<li
				v-if="drawEditor"
				:class="{active: isActive('draw')}"
				@click.prevent="setActive('draw')">
				<img :src="$options.icons.drawnIcon" alt="draw">
				Draw
			</li>
		</ul>

		<div class="content">
			<Editor
				v-show="isActive('draw')"
				:class="{'active show': isActive('draw')}"
				@close="close"
				@save="save" />
			<TextInput
				v-show="isActive('text')"
				ref="text"
				:class="{'active show': isActive('text')}"
				@save="save"
				@close="close" />
		</div>
	</div>
</template>

<script>
import Editor from './Editor.vue'
import TextInput from './TextInput.vue'
import DrawIcon from '../../assets/images/curvature.png'
import TextIcon from '../../assets/images/text.png'

export default {
	name: 'Draw',
	components: { TextInput, Editor },
	props: {
		drawEditor: {
			type: Boolean,
			required: false,
			default: true,
		},
		textEditor: {
			type: Boolean,
			required: false,
			default: false,
		},
	},

	icons: {
		drawnIcon: DrawIcon,
		textIcon: TextIcon,
	},

	data: () => ({
		toolSelected: 'draw',
	}),

	methods: {
		isActive(tabItem) {
			return this.toolSelected === tabItem
		},
		close() {
			this.$emit('close')
		},
		save(param) {
			this.$emit('save', param)
		},
		setActive(tabItem) {
			if (tabItem === 'text') {
				this.$refs.text.setFocus()
			}
			this.toolSelected = tabItem
		},
	},
}
</script>
<style lang="scss" scoped>
.container{
	display: flex;
	flex-direction: column;
	// width: calc(100% - 20px);
	width: 380px;
	height: 100%;
	margin-top: 10px;

	ul{
		display: flex;
		flex-direction: row;
		height: 49px;

		li{
			padding: 10px;
			position: absolute;
			border: 1px solid #dbdbdbad;
			border-radius: 5px 5px 0 0;
			cursor: pointer;

			img{
				width: 14px;
				margin-right: 10px;
			}

			&:nth-child(2n){
				margin-left: 85px;
			}

		}
	}

	.content{
		width: 100%;
		border: 1px solid #dbdbdb;
	}

	li.active{
		border: 1px solid #dbdbdb;
		border-bottom: 1px solid #fff;
		margin-top: -1px;
		border-radius: 5px 5px 0 0;
	}
}
</style>
