/*
 * DataViews uses createSelector, added after our minimum WordPress version.
 * Bundle its official implementation while retaining WordPress's shared data
 * registry and preference persistence. Do not create a second registry.
 */
import * as sharedData from '@llamahire/wordpress-data';
import { createSelector as bundledCreateSelector } from '../node_modules/@wordpress/data/build-module/create-selector.mjs';

export const { combineReducers, createReduxStore, register, select, useDispatch, useRegistry, useSelect } = sharedData;
export const createSelector = sharedData.createSelector || bundledCreateSelector;
