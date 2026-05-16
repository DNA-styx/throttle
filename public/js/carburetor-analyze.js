'use strict';

if (!String.prototype.padStart) {
	String.prototype.padStart = function padStart(targetLength,padString) {
		targetLength = targetLength>>0; //floor if number or convert non-number to 0;
		padString = String(padString || ' ');
		if (this.length > targetLength) {
			return String(this);
		}
		else {
			targetLength = targetLength-this.length;
			if (targetLength > padString.length) {
				padString += padString.repeat(targetLength/padString.length); //append to original to ensure we are longer than needed
			}
			return padString.slice(0,targetLength) + String(this);
		}
	};
}

if (!String.prototype.padEnd) {
	String.prototype.padEnd = function padEnd(targetLength,padString) {
		targetLength = targetLength>>0; //floor if number or convert non-number to 0;
		padString = String(padString || ' ');
		if (this.length > targetLength) {
			return String(this);
		}
		else {
			targetLength = targetLength-this.length;
			if (targetLength > padString.length) {
				padString += padString.repeat(targetLength/padString.length); //append to original to ensure we are longer than needed
			}
			return String(this) + padString.slice(0,targetLength);
		}
	};
}

const FRAME_TRUST = [
	'unknown',
	'stack scanning',
	'call frame info with scanning',
	'previous frame\'s frame pointer',
	'call frame info',
	'external stack walker',
	'instruction pointer in context',
];

function normalize_frame_value(value) {
	if (value === null || value === undefined) {
		return '';
	}

	if (typeof value === 'string' || typeof value === 'number') {
		return String(value).trim();
	}

	if (typeof value === 'object') {
		const preferred = ['rendered', 'name', 'function', 'code_file', 'debug_file', 'file', 'filename', 'module', 'path'];
		for (let i = 0; i < preferred.length; ++i) {
			const candidate = value[preferred[i]];
			if (typeof candidate === 'string' && candidate.trim() !== '') {
				return candidate.trim();
			}
		}
	}

	return '';
}

function collect_register_lines(indent, registers) {
	let order = [
		"eip", "esp", "ebp", "ebx",
		"esi", "edi", "eax", "ecx",
		"edx", "efl",
		"rax", "rdx", "rcx", "rbx",
		"rsi", "rdi", "rbp", "rsp",
		 "r8",  "r9", "r10", "r11",
		"r12", "r13", "r14",
		"r15", "rip",
	];

	let source = order;
	let printed = {};
	let register_count = 0;
	let line = indent;
	let lines = [];

	for (let i = 0; i < 2; ++i) {
		for (let register in source) {
			if (i === 0) {
				register = order[register];
			}

			if (printed[register] || registers[register] === undefined) {
				continue;
			}

			line += register + ': 0x' + registers[register].toString(16).padStart(8, '0');

			register_count++;

			if (register_count < 4) {
				line += '  ';
			} else {
				lines.push(line);

				register_count = 0;
				line = indent;
			}

			printed[register] = true;
		}

		source = registers;
	}

	if (register_count > 0) {
		lines.push(line);
	}

	return lines;
}

function print_registers(indent, registers) {
	collect_register_lines(indent, registers).forEach(function(line) {
		console.log(line);
	});
}

function collect_stack_lines(indent, base, memory) {
	const kAddressBytes = 4;
	const kHeader = false;
	const kRowBytes = 16;
	const kChunkBytes = 8;
	const kChunkText = false;
	let lines = [];

	if (kHeader) {
		let line = indent + ' '.repeat(kAddressBytes * 2) + '  ';
		let string = '';
		for (let i = 0; i < kRowBytes; ++i) {
			line += i.toString(16).padStart(2, ' ') + ' ';
			string += ' ';
			//string += i.toString(16).padStart(2, ' ').substr(1, 1);

			if (kChunkBytes > 0 && i < (kRowBytes - 1) && (i % kChunkBytes) === (kChunkBytes - 1)) {
				line += ' ';
				if (kChunkText) {
					string += ' ';
				}
			}
		}
		line += '  ' + string + ' ';
		lines.push(line);
	}

	for (let offset = 0; offset < memory.length;) {
		let line = indent + (base + offset).toString(16).padStart(kAddressBytes * 2, '0') + '  ';

		let string = '';
		for (let i = 0; i < kRowBytes; ++i, ++offset) {
			if (offset < memory.length) {
				line += memory[offset].toString(16).padStart(2, '0') + ' ';

				const character = String.fromCharCode(memory[offset]);
				string += (character.length === 1 && character.match(/^[ -~]$/)) ? character : '.';
			} else {
				line += '   ';
				string += ' ';
			}

			if (kChunkBytes > 0 && i < (kRowBytes - 1) && (i % kChunkBytes) === (kChunkBytes - 1)) {
				line += ' ';

				if (kChunkText) {
					string += ' ';
				}
			}
		}

		line += ' ' + string;

		lines.push(line);
	}

	return lines;
}

function print_stack(indent, base, memory) {
	collect_stack_lines(indent, base, memory).forEach(function(line) {
		console.log(line);
	});
}

function collect_instruction_lines(indent, ip, instructions) {
	let bytes_per_line = 0;
	let crash_opcode = -1;
	let lines = [];

	for (let i = 0; i < instructions.length; ++i) {
		if (ip >= instructions[i].offset && (i === (instructions.length - 1) || ip < instructions[i + 1].offset)) {
			crash_opcode = i;
			break;
		}
	}

	if (crash_opcode >= 0) {
		for (let i = 0; i < instructions.length; ++i) {
			if (i < (crash_opcode - 5)) {
				continue;
			}

			if (i > (crash_opcode + 5)) {
				break;
			}

			const bytes = instructions[i].hex.length / 2;
			if (bytes > bytes_per_line) {
				bytes_per_line = bytes;
			}
		}
	}

	for (let i = 0; i < instructions.length; ++i) {
		if (crash_opcode >= 0) {
			if (i < (crash_opcode - 5)) {
				continue;
			}

			if (i > (crash_opcode + 5)) {
				break;
			}
		}

		let line = indent;

		if (crash_opcode >= 0 && i === crash_opcode) {
			line = '  >' + line.substr(3);
		}

		line += instructions[i].offset.toString(16).padStart(8, '0');
		line += '  ';
		line += instructions[i].hex.match(/.{2}/g).join(' ').padEnd((bytes_per_line * 3) - 1, ' ');
		line += '  ';
		line += instructions[i].mnemonic;

		lines.push(line);
	}

	return lines;
}

function print_instructions(indent, ip, instructions) {
	collect_instruction_lines(indent, ip, instructions).forEach(function(line) {
		console.log(line);
	});
}

function collect_thread_render(i, crashed, thread) {
	let title = 'Thread ' + i;
	if (crashed) {
		title += ' (crashed)';
	}
	title += ':';

	let lines = [title, ''];
	let frames = [];

	let num_frames = thread.length;
	/*if (num_frames > 10) {
		num_frames = 10;
	}*/

	for (let i = 0; i < num_frames; ++i) {
		const frame = thread[i];

		const prefix = i.toString().padStart((num_frames - 1).toString().length, ' ') + ': ';
		const indent = '  ' + ' '.repeat(prefix.length);
		const startLine = lines.length;
		const label = '  ' + prefix + frame.rendered;

		lines.push(label);
		frames.push({
			threadIndex: i,
			frameIndex: i,
			startLine: startLine,
			label: frame.rendered,
			module: normalize_frame_value(frame.module),
			function: normalize_frame_value(frame.function)
		});

		if (frame.url) {
			lines.push(indent + frame.url);
		}

		if (frame.registers) {
			lines = lines.concat(collect_register_lines(indent, frame.registers));
			lines.push('');
		}

		if (frame.instructions) {
			lines = lines.concat(collect_instruction_lines(indent, frame.instruction, frame.instructions));
			lines.push('');
		}

		if (frame.stack) {
			lines = lines.concat(collect_stack_lines(indent, frame.registers && frame.registers.esp, base64ToUint8Array(frame.stack)));
			lines.push('');
		}

		lines.push(indent + 'Found via ' + (FRAME_TRUST[frame.trust] || FRAME_TRUST[0]));
		lines.push('');
		lines.push('');
	}

	return { lines: lines, frames: frames };
}

function print_thread(i, crashed, thread) {
	const rendered = collect_thread_render(i, crashed, thread);
	rendered.lines.forEach(function(line) {
		console.log(line);
	});
}

function analyzeToText(data) {
	let lines = [];
	let frames = [];

	if (data.crashed) {
		lines.push(data.crash_reason + ' accessing 0x' + data.crash_address.toString(16));
		lines.push('');
	}

	if (typeof data.requesting_thread !== 'undefined' && data.requesting_thread >= 0) {
		const thread = data.threads[data.requesting_thread];
		const rendered = collect_thread_render(data.requesting_thread, true, thread);
		const lineOffset = lines.length;
		lines = lines.concat(rendered.lines);
		frames = frames.concat(rendered.frames.map(function(frame) {
			return {
				threadIndex: data.requesting_thread,
				frameIndex: frame.frameIndex,
				startLine: frame.startLine + lineOffset,
				label: frame.label,
				module: frame.module,
				function: frame.function
			};
		}));
	}

	for (let i = 0; i < data.threads.length; ++i) {
		if (i === data.requesting_thread) {
			continue;
		}

		const thread = data.threads[i];
		lines = lines.concat(collect_thread_render(i, false, thread).lines);
	}

	return {
		text: lines.join('\n'),
		frames: frames
	};
}

function base64ToUint8Array(base64) {
	var binary_string =  window.atob(base64);
	var len = binary_string.length;
	var bytes = new Uint8Array( len );
	for (var i = 0; i < len; i++)		{
		bytes[i] = binary_string.charCodeAt(i);
	}
	return bytes;
}

function analyze(data) {
	analyzeToText(data).text.split('\n').forEach(function(line) {
		console.log(line);
	});
}
